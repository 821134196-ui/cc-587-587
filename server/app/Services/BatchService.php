<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchEvent;
use App\Models\DeviceReading;
use App\Models\Equipment;
use App\Models\ParameterVersion;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 批次流转核心规则:
 *  - 登记产出:数量必须守恒(合格+返工+报废 = 投入),设备读数不得超限,否则停在待处理
 *  - 质检放行:质检员不能批准自己登记的批次;末工序放行即完成
 *  - 接收:仅 released 状态可接收,原子更新防止两个工位同时占用同一批
 *  - 拆分:子批数量之和必须等于父批在手数量,子批继承父批锁定的参数版本
 *  - 返工:沿原生产单生成返工子批回流到前工序,父批返工数量相应扣减
 */
class BatchService
{
    public function __construct(private readonly DeviceSimulator $simulator) {}

    /**
     * 创建生产单的根批次,直接投入首工序并锁定当前参数版本。
     */
    public function createRootBatch(ProductionOrder $order, int $qty, User $operator, ?Equipment $equipment = null): Batch
    {
        return DB::transaction(function () use ($order, $qty, $operator, $equipment) {
            $process = Process::firstStation();
            abort_if($process === null, 500, '尚未配置任何工序');

            $equipment ??= $process->equipments()->first();
            abort_if($equipment === null, 500, "工序 [{$process->name}] 尚未配置设备");

            $version = $equipment->activeParameterVersion;

            $batch = Batch::create([
                'code' => $this->nextCode($order),
                'production_order_id' => $order->id,
                'origin' => Batch::ORIGIN_ROOT,
                'current_process_id' => $process->id,
                'current_equipment_id' => $equipment->id,
                'parameter_version_id' => $version?->id,
                'qty_initial' => $qty,
                'qty_received' => $qty,
                'status' => Batch::STATUS_IN_PRODUCTION,
            ]);

            $this->record($batch, BatchEvent::TYPE_CREATE, $operator, [
                'to_process_id' => $process->id,
                'to_equipment_id' => $equipment->id,
                'parameter_version_id' => $version?->id,
                'qty' => $qty,
                'note' => '批次创建,投入首工序',
            ]);

            return $batch;
        });
    }

    /**
     * 操作员登记产出。数量不符或读数超限 → 停在待处理(on_hold),不得转序。
     * on_hold 状态允许重新登记以修正。
     *
     * @param  array{qty_qualified:int, qty_rework:int, qty_scrap:int, readings?:array}  $data
     */
    public function registerOutput(Batch $batch, array $data, User $operator): Batch
    {
        return DB::transaction(function () use ($batch, $data, $operator) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            if (! in_array($batch->status, [Batch::STATUS_IN_PRODUCTION, Batch::STATUS_ON_HOLD], true)) {
                throw new ConflictHttpException("当前状态 [{$batch->status}] 不允许登记产出");
            }

            $qualified = (int) $data['qty_qualified'];
            $rework = (int) $data['qty_rework'];
            $scrap = (int) $data['qty_scrap'];

            // 采集设备读数(按批次锁定的参数版本判定)
            $version = $batch->parameterVersion;
            $readings = [];
            if ($version !== null) {
                $readings = $this->simulator->generate($version, $data['readings'] ?? []);
                foreach ($readings as $r) {
                    DeviceReading::create([
                        'batch_id' => $batch->id,
                        'equipment_id' => $batch->current_equipment_id,
                        'parameter_version_id' => $version->id,
                        'metric' => $r['metric'],
                        'value' => $r['value'],
                        'min_value' => $r['min'],
                        'max_value' => $r['max'],
                        'in_limit' => $r['in_limit'],
                    ]);
                }
            }

            $batch->qty_qualified = $qualified;
            $batch->qty_rework = $rework;
            $batch->qty_scrap = $scrap;
            $batch->registered_by = $operator->id;
            $batch->qc_by = null;
            $batch->qc_at = null;

            // 规则一:数量核对
            if ($qualified + $rework + $scrap !== $batch->qty_received) {
                $batch->status = Batch::STATUS_ON_HOLD;
                $batch->hold_reason = sprintf(
                    '数量不符:合格 %d + 返工 %d + 报废 %d = %d,投入 %d',
                    $qualified, $rework, $scrap, $qualified + $rework + $scrap, $batch->qty_received
                );
                $batch->save();
                $this->record($batch, BatchEvent::TYPE_HOLD, $operator, [
                    'qty_qualified' => $qualified, 'qty_rework' => $rework, 'qty_scrap' => $scrap,
                    'note' => $batch->hold_reason,
                ]);

                return $batch;
            }

            // 规则二:工艺参数不得超限
            $outOfLimit = array_filter($readings, fn ($r) => ! $r['in_limit']);
            if ($outOfLimit !== []) {
                $metrics = implode('、', array_map(
                    fn ($r) => "{$r['metric']}={$r['value']}(限 {$r['min']}~{$r['max']})",
                    $outOfLimit
                ));
                $batch->status = Batch::STATUS_ON_HOLD;
                $batch->hold_reason = "工艺参数超限:{$metrics}";
                $batch->save();
                $this->record($batch, BatchEvent::TYPE_HOLD, $operator, [
                    'qty_qualified' => $qualified, 'qty_rework' => $rework, 'qty_scrap' => $scrap,
                    'parameter_version_id' => $version?->id,
                    'note' => $batch->hold_reason,
                ]);

                return $batch;
            }

            $batch->status = Batch::STATUS_PENDING_QC;
            $batch->hold_reason = null;
            $batch->save();

            $this->record($batch, BatchEvent::TYPE_OUTPUT, $operator, [
                'from_process_id' => $batch->current_process_id,
                'from_equipment_id' => $batch->current_equipment_id,
                'parameter_version_id' => $version?->id,
                'qty_qualified' => $qualified, 'qty_rework' => $rework, 'qty_scrap' => $scrap,
                'note' => '产出登记完成,待质检',
            ]);

            return $batch;
        });
    }

    /**
     * 质检放行。质检员不能批准自己登记的批次;末工序放行即完成。
     */
    public function qcRelease(Batch $batch, User $qc, ?string $note = null): Batch
    {
        return DB::transaction(function () use ($batch, $qc, $note) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== Batch::STATUS_PENDING_QC) {
                throw new ConflictHttpException("当前状态 [{$batch->status}] 不能质检放行");
            }
            if ($batch->registered_by === $qc->id) {
                throw new HttpException(403, '操作员不能批准自己生产的批次');
            }

            $isLast = $batch->process->isLast();
            $batch->status = $isLast ? Batch::STATUS_COMPLETED : Batch::STATUS_RELEASED;
            $batch->qc_by = $qc->id;
            $batch->qc_at = now();
            $batch->save();

            $this->record($batch, BatchEvent::TYPE_QC_RELEASE, $qc, [
                'from_process_id' => $batch->current_process_id,
                'qty' => $batch->qty_qualified,
                'note' => $note ?? '质检放行',
            ]);
            if ($isLast) {
                $this->record($batch, BatchEvent::TYPE_COMPLETE, $qc, [
                    'qty' => $batch->qty_qualified,
                    'note' => '末工序放行,批次完成',
                ]);
            }

            return $batch;
        });
    }

    /**
     * 质检驳回 → 待处理。
     */
    public function qcReject(Batch $batch, User $qc, ?string $note = null): Batch
    {
        return DB::transaction(function () use ($batch, $qc, $note) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== Batch::STATUS_PENDING_QC) {
                throw new ConflictHttpException("当前状态 [{$batch->status}] 不能质检驳回");
            }
            if ($batch->registered_by === $qc->id) {
                throw new HttpException(403, '操作员不能处理自己登记的批次');
            }

            $batch->status = Batch::STATUS_ON_HOLD;
            $batch->hold_reason = '质检驳回'.($note ? ":{$note}" : '');
            $batch->qc_by = $qc->id;
            $batch->qc_at = now();
            $batch->save();

            $this->record($batch, BatchEvent::TYPE_QC_REJECT, $qc, [
                'from_process_id' => $batch->current_process_id,
                'note' => $batch->hold_reason,
            ]);

            return $batch;
        });
    }

    /**
     * 下一工位接收。原子状态翻转,两个工位不能同时占用同一批。
     * 接收时锁定接收设备当前 active 的参数版本;
     * 若仍有未处理的返工数量,自动生成返工子批留在本工序(保证追溯链不断)。
     */
    public function receive(Batch $batch, Equipment $equipment, User $operator): Batch
    {
        return DB::transaction(function () use ($batch, $equipment, $operator) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            // 先查状态:已被其他工位接收的批次直接拒绝(原子更新兜底并发)
            if ($batch->status !== Batch::STATUS_RELEASED) {
                throw new ConflictHttpException('该批次已被其他工位接收或当前状态不允许接收');
            }

            $next = $batch->process->nextStation();
            if ($next === null) {
                throw new ConflictHttpException('当前已是末工序,无需接收');
            }
            if ($equipment->process_id !== $next->id) {
                throw ValidationException::withMessages([
                    'equipment_id' => "设备 [{$equipment->name}] 不属于下一工序 [{$next->name}]",
                ]);
            }

            $version = $equipment->activeParameterVersion;
            $fromProcess = $batch->process;
            $fromEquipment = $batch->equipment;
            $moveQty = $batch->qty_qualified;
            $leftoverRework = $batch->qty_rework;

            // 原子占位:只有 released 状态能被接收,并发下只有一个工位成功
            $affected = Batch::where('id', $batch->id)
                ->where('status', Batch::STATUS_RELEASED)
                ->update([
                    'current_process_id' => $next->id,
                    'current_equipment_id' => $equipment->id,
                    'parameter_version_id' => $version?->id,
                    'qty_received' => $moveQty,
                    'qty_qualified' => 0,
                    'qty_rework' => 0,
                    'qty_scrap' => 0,
                    'status' => Batch::STATUS_IN_PRODUCTION,
                    'hold_reason' => null,
                    'registered_by' => null,
                    'qc_by' => null,
                    'qc_at' => null,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw new ConflictHttpException('该批次已被其他工位接收或当前状态不允许接收');
            }

            // 未处理的返工数量自动生成返工子批,留在原工序继续处理
            if ($leftoverRework > 0) {
                $this->createReworkChild($batch->fresh(), $fromProcess, $fromEquipment, $leftoverRework, $operator, true);
            }

            $this->record($batch->fresh(), BatchEvent::TYPE_RECEIVE, $operator, [
                'from_process_id' => $fromProcess->id,
                'to_process_id' => $next->id,
                'from_equipment_id' => $fromEquipment?->id,
                'to_equipment_id' => $equipment->id,
                'parameter_version_id' => $version?->id,
                'qty' => $moveQty,
                'note' => "接收 {$moveQty} 件,锁定参数版本 ".($version ? "v{$version->version}" : '无'),
            ]);

            return $batch->fresh();
        });
    }

    /**
     * 拆分批次:子批数量之和必须等于父批在手数量;子批继承父批锁定的参数版本。
     *
     * @param  array<int, int>  $quantities
     * @return array<int, Batch>
     */
    public function split(Batch $batch, array $quantities, User $operator): array
    {
        return DB::transaction(function () use ($batch, $quantities, $operator) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== Batch::STATUS_IN_PRODUCTION) {
                throw new ConflictHttpException('只有在制状态的批次可以拆分');
            }
            $quantities = array_values(array_map('intval', $quantities));
            if (count($quantities) < 2 || min($quantities) < 1) {
                throw ValidationException::withMessages(['quantities' => '至少拆成 2 批,每批数量不小于 1']);
            }
            if (array_sum($quantities) !== $batch->qty_received) {
                throw ValidationException::withMessages([
                    'quantities' => "拆分数量之和必须等于在手数量 {$batch->qty_received}",
                ]);
            }

            $children = [];
            foreach ($quantities as $i => $qty) {
                $children[] = Batch::create([
                    'code' => $batch->code.'-S'.($i + 1),
                    'production_order_id' => $batch->production_order_id,
                    'parent_batch_id' => $batch->id,
                    'origin' => Batch::ORIGIN_SPLIT,
                    'current_process_id' => $batch->current_process_id,
                    'current_equipment_id' => $batch->current_equipment_id,
                    'parameter_version_id' => $batch->parameter_version_id, // 沿用父批锁定的版本
                    'qty_initial' => $qty,
                    'qty_received' => $qty,
                    'status' => Batch::STATUS_IN_PRODUCTION,
                ]);
            }

            $batch->status = Batch::STATUS_SPLIT;
            $batch->save();

            $this->record($batch, BatchEvent::TYPE_SPLIT, $operator, [
                'from_process_id' => $batch->current_process_id,
                'qty' => $batch->qty_received,
                'meta' => ['child_batch_ids' => array_map(fn ($c) => $c->id, $children)],
                'note' => '拆分为 '.count($children).' 个子批:'.implode(', ', array_map(fn ($c) => "{$c->code}({$c->qty_received})", $children)),
            ]);
            foreach ($children as $child) {
                $this->record($child, BatchEvent::TYPE_CREATE, $operator, [
                    'to_process_id' => $child->current_process_id,
                    'to_equipment_id' => $child->current_equipment_id,
                    'parameter_version_id' => $child->parameter_version_id,
                    'qty' => $child->qty_received,
                    'note' => "由批次 {$batch->code} 拆分产生",
                ]);
            }

            return $children;
        });
    }

    /**
     * 返工回流:从父批的待返工数量中抽出 qty,生成返工子批回到指定前工序。
     * 子批沿原生产单保存关联。
     */
    public function rework(Batch $batch, Process $toProcess, Equipment $equipment, int $qty, User $operator): Batch
    {
        return DB::transaction(function () use ($batch, $toProcess, $equipment, $qty, $operator) {
            $batch = Batch::lockForUpdate()->findOrFail($batch->id);

            if ($qty < 1 || $qty > $batch->qty_rework) {
                throw ValidationException::withMessages([
                    'qty' => "返工数量须在 1 ~ {$batch->qty_rework} 之间",
                ]);
            }
            if ($toProcess->sequence > $batch->process->sequence) {
                throw ValidationException::withMessages([
                    'to_process_id' => '返工只能回流到当前工序或之前的工序',
                ]);
            }
            if ($equipment->process_id !== $toProcess->id) {
                throw ValidationException::withMessages([
                    'equipment_id' => "设备 [{$equipment->name}] 不属于工序 [{$toProcess->name}]",
                ]);
            }

            $batch->qty_rework -= $qty;
            $batch->save();

            return $this->createReworkChild($batch, $toProcess, $equipment, $qty, $operator, false);
        });
    }

    /**
     * 生成返工子批并留痕(手动返工与接收时自动留存共用)。
     */
    private function createReworkChild(Batch $parent, Process $toProcess, ?Equipment $equipment, int $qty, User $operator, bool $auto): Batch
    {
        $equipment ??= $toProcess->equipments()->first();
        $version = $equipment?->activeParameterVersion;
        $seq = $parent->children()->where('origin', Batch::ORIGIN_REWORK)->count() + 1;

        $child = Batch::create([
            'code' => "{$parent->code}-R{$seq}",
            'production_order_id' => $parent->production_order_id,
            'parent_batch_id' => $parent->id,
            'origin' => Batch::ORIGIN_REWORK,
            'current_process_id' => $toProcess->id,
            'current_equipment_id' => $equipment?->id,
            'parameter_version_id' => $version?->id,
            'qty_initial' => $qty,
            'qty_received' => $qty,
            'status' => Batch::STATUS_IN_PRODUCTION,
        ]);

        $this->record($parent, BatchEvent::TYPE_REWORK, $operator, [
            'from_process_id' => $parent->current_process_id,
            'to_process_id' => $toProcess->id,
            'to_equipment_id' => $equipment?->id,
            'qty' => $qty,
            'meta' => ['child_batch_ids' => [$child->id], 'auto' => $auto],
            'note' => ($auto ? '接收时自动留存返工子批 ' : '返工回流生成子批 ').$child->code,
        ]);
        $this->record($child, BatchEvent::TYPE_CREATE, $operator, [
            'to_process_id' => $toProcess->id,
            'to_equipment_id' => $equipment?->id,
            'parameter_version_id' => $version?->id,
            'qty' => $qty,
            'note' => "由批次 {$parent->code} 返工产生",
        ]);

        return $child;
    }

    /**
     * 参数换版:旧版本作废,新版本生效。已锁定旧版本的批次不受影响。
     */
    public function reviseParameters(Equipment $equipment, array $params, User $user): ParameterVersion
    {
        return DB::transaction(function () use ($equipment, $params, $user) {
            $equipment->parameterVersions()
                ->where('status', ParameterVersion::STATUS_ACTIVE)
                ->update(['status' => ParameterVersion::STATUS_SUPERSEDED]);

            $nextVersion = ((int) $equipment->parameterVersions()->max('version')) + 1;

            return ParameterVersion::create([
                'equipment_id' => $equipment->id,
                'version' => $nextVersion,
                'params' => $params,
                'status' => ParameterVersion::STATUS_ACTIVE,
                'created_by' => $user->id,
            ]);
        });
    }

    private function nextCode(ProductionOrder $order): string
    {
        $seq = $order->batches()->where('origin', Batch::ORIGIN_ROOT)->count() + 1;

        return "{$order->code}-B{$seq}";
    }

    private function record(Batch $batch, string $type, ?User $actor, array $extra = []): BatchEvent
    {
        return BatchEvent::create(array_merge([
            'batch_id' => $batch->id,
            'production_order_id' => $batch->production_order_id,
            'type' => $type,
            'actor_id' => $actor?->id,
        ], $extra));
    }
}
