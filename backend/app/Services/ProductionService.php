<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Batch;
use App\Models\BatchTransfer;
use App\Models\Equipment;
use App\Models\ParameterVersion;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\ProductionRecord;
use App\Models\QcInspection;
use App\Models\ReworkOrder;
use App\Models\ScrapRecord;
use App\Models\SplitRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ProductionService
{
    public function __construct(
        private readonly EquipmentSimulator $simulator,
        private readonly TraceService $trace,
    ) {}

    /* ============================ 生产单 ============================ */

    public function createOrder(User $user, array $data): ProductionOrder
    {
        return DB::transaction(function () use ($user, $data) {
            $productCode = $data['product_code'];
            $firstProcess = Process::where('product_code', $productCode)
                ->where('is_active', true)
                ->orderBy('sequence')->first();
            if (! $firstProcess) {
                throw new BusinessException("产品 {$productCode} 没有可用的工艺路线");
            }

            $order = ProductionOrder::create([
                'order_no' => $data['order_no'] ?? $this->genOrderNo(),
                'product_code' => $productCode,
                'product_name' => $data['product_name'],
                'planned_qty' => $data['planned_qty'],
                'status' => 'planned',
                'note' => $data['note'] ?? null,
                'created_by' => $user->id,
            ]);

            // 根批次：在首道工序排队，尚未被任何工位接收
            $root = Batch::create([
                'batch_no' => $order->order_no.'-B1',
                'production_order_id' => $order->id,
                'root_batch_id' => null,
                'parent_batch_id' => null,
                'origin' => 'root',
                'depth' => 0,
                'product_code' => $productCode,
                'process_id' => $firstProcess->id,
                'station_id' => null,
                'equipment_id' => null,
                'qty' => $data['planned_qty'],
                'status' => Batch::QUEUED,
                'created_by' => $user->id,
            ]);
            $root->update(['root_batch_id' => $root->id]);

            $this->trace->log($order, $root, 'order_created', null,
                $this->node($firstProcess->station, $firstProcess),
                $order->planned_qty, ['planned_qty' => $order->planned_qty], $user);

            return $order->fresh();
        });
    }

    public function releaseOrder(ProductionOrder $order, User $user): ProductionOrder
    {
        return DB::transaction(function () use ($order, $user) {
            $order = ProductionOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($order->status !== 'planned') {
                throw new BusinessException('生产单已下发，不能重复下发');
            }
            $order->update(['status' => 'released']);
            $root = $order->batches()->whereNull('parent_batch_id')->firstOrFail();
            $this->trace->log($order, $root, 'order_released', null,
                $this->node($root->process?->station, $root->process),
                $root->qty, null, $user);
            return $order->fresh();
        });
    }

    /* ============================ 接收批次 ============================ */

    /**
     * 工位接收批次。
     * - 首工序排队：只有工艺定义的工位能接收
     * - 已放行在途：只有转序单目标工位能接收（其他工位占用被拒绝）
     * 接收瞬间锁定该工序当前生效的工艺参数版本（换版不影响在制批次）
     */
    public function receive(Batch $batch, User $user): Batch
    {
        return DB::transaction(function () use ($batch, $user) {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertActiveOperator($user);
            $stationId = $user->station_id;
            if (! $stationId) {
                throw new BusinessException('当前用户未分配工位，无法接收批次');
            }

            $process = $batch->process;
            if (! $process) {
                throw new BusinessException('批次没有目标工序，无法接收');
            }

            $transfer = null;
            if ($batch->status === Batch::RELEASED) {
                $transfer = $batch->transfers()->where('status', BatchTransfer::PENDING)
                    ->lockForUpdate()->latest('id')->first();
                if (! $transfer) {
                    throw new BusinessException('批次处于已放行状态但找不到在途转序单');
                }
                if ($transfer->to_station_id !== $stationId) {
                    throw new BusinessException(
                        "批次已被工位 {$transfer->toStation?->code} 预定接收，两个工位不能同时占用同一批"
                    );
                }
                $targetProcess = Process::findOrFail($transfer->to_process_id);
                if ($targetProcess->id !== $process->id) {
                    $process = $targetProcess;
                }
            } elseif ($batch->status === Batch::QUEUED) {
                if ($process->station_id !== $stationId) {
                    throw new BusinessException(
                        "该批次应由工位 {$process->station?->code} 接收，当前工位不能占用"
                    );
                }
            } else {
                throw new BusinessException("批次状态为 {$batch->status}，不能被接收");
            }

            // 锁定本工序当前生效参数版本；之后即使全局换版，本批次仍用此版本
            $version = ParameterVersion::active()
                ->where('process_id', $process->id)
                ->orderByDesc('id')->first();
            if (! $version) {
                throw new BusinessException("工序 {$process->code} 没有已启用的工艺参数版本，无法接收（请工艺工程师先启用版本）");
            }

            $fromNode = $this->node($batch->station, $batch->process, $batch->equipment);

            $batch->update([
                'status' => Batch::IN_PROGRESS,
                'station_id' => $stationId,
                'process_id' => $process->id,
                'equipment_id' => null,
                'param_version_id' => $version->id,
                'on_hold_reason' => null,
            ]);

            if ($transfer) {
                $transfer->update([
                    'status' => BatchTransfer::ACCEPTED,
                    'accepted_by' => $user->id,
                    'accepted_at' => now(),
                ]);
            }

            // 若该批次是返工回流批次，接收即表示返工开始
            if ($batch->origin === 'rework') {
                ReworkOrder::where('rework_batch_id', $batch->id)
                    ->where('status', ReworkOrder::OPEN)
                    ->update(['status' => ReworkOrder::IN_PROGRESS]);
            }

            $this->trace->log(
                $batch->order, $batch,
                $transfer ? 'transfer_received' : 'received',
                $fromNode, $this->node($user->station, $process),
                $batch->qty,
                ['param_version' => $version->version, 'param_version_id' => $version->id],
                $user
            );

            return $batch->fresh();
        });
    }

    /* ======================= 设备读数（本地模拟） ======================= */

    public function previewReading(Batch $batch, array $perturb = []): array
    {
        $batch = $batch->fresh();
        if (! $batch->param_version_id) {
            throw new BusinessException('批次尚未接收，无锁定的参数版本');
        }
        $version = $batch->paramVersion;
        $equipment = $batch->station?->equipment()->first();
        if (! $equipment) {
            throw new BusinessException('工位未配置设备');
        }
        $reading = $this->simulator->read($equipment, $version, $batch, $perturb);
        return [
            'equipment_id' => $equipment->id,
            'equipment_code' => $equipment->code,
            'version' => $version->version,
            'payload' => $reading->payload,
        ];
    }

    /* ========================== 登记产出 ========================== */

    /**
     * 操作员完成当前工序后登记产出。
     * 守恒：good + rework + scrap 必须 == input(批次数量)，不符挂起。
     * 参数：实际读数超出锁定版本上下限 → 挂起，不得流转。
     */
    public function registerProduction(Batch $batch, User $user, array $data): ProductionRecord
    {
        return DB::transaction(function () use ($batch, $user, $data) {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            $this->assertActiveOperator($user);

            if ($batch->status !== Batch::IN_PROGRESS) {
                throw new BusinessException("批次状态为 {$batch->status}，只有加工中的批次可以登记产出");
            }
            if ($batch->station_id !== $user->station_id) {
                throw new BusinessException('批次不在你的工位，不能登记');
            }
            if (! $batch->param_version_id) {
                throw new BusinessException('批次未锁定工艺参数版本');
            }

            $equipment = Equipment::query()->lockForUpdate()->findOrFail($data['equipment_id'] ?? 0);
            if ($equipment->station_id !== $batch->station_id) {
                throw new BusinessException("设备 {$equipment->code} 不属于当前工位");
            }
            if ($equipment->status === 'maintenance') {
                throw new BusinessException("设备 {$equipment->code} 处于维护状态");
            }

            // 同一设备在批次放行前不得加工另一个批次（含加工中/待质检/挂起的占用）
            $busy = Batch::where('equipment_id', $equipment->id)
                ->whereIn('status', [Batch::IN_PROGRESS, Batch::QC_PENDING, Batch::ON_HOLD])
                ->where('id', '!=', $batch->id)
                ->exists();
            if ($busy) {
                throw new BusinessException("设备 {$equipment->code} 正在加工其他批次");
            }

            $version = ParameterVersion::with('items.template')->findOrFail($batch->param_version_id);
            $good = (int) ($data['good_qty'] ?? 0);
            $rework = (int) ($data['rework_qty'] ?? 0);
            $scrap = (int) ($data['scrap_qty'] ?? 0);
            $input = (int) $batch->qty;
            if ($good < 0 || $rework < 0 || $scrap < 0) {
                throw new BusinessException('数量不能为负数');
            }

            // 设备读数：前端未给实测值时本地模拟采集
            if (! empty($data['actual_params'])) {
                $actual = $this->normalizeParams($data['actual_params']);
            } else {
                $reading = $this->simulator->read($equipment, $version, $batch,
                    is_array($data['perturb'] ?? null) ? $data['perturb'] : []);
                $actual = $reading->payload;
            }
            $reading = $this->simulator->snapshot($equipment, $actual, $batch);

            $violations = $this->checkParamRanges($version, $actual);

            $record = new ProductionRecord([
                'batch_id' => $batch->id,
                'process_id' => $batch->process_id,
                'station_id' => $batch->station_id,
                'equipment_id' => $equipment->id,
                'operator_id' => $user->id,
                'param_version_id' => $version->id,
                'shift' => $data['shift'] ?? null,
                'input_qty' => $input,
                'good_qty' => $good,
                'rework_qty' => $rework,
                'scrap_qty' => $scrap,
                'actual_params' => $actual,
                'equipment_reading_id' => $reading->id,
                'check_status' => 'normal',
                'note' => $data['note'] ?? null,
            ]);

            // 数量核对
            $accounted = $good + $rework + $scrap;
            $qtyIssues = [];
            if ($accounted !== $input) {
                $qtyIssues[] = "登记合计 {$accounted}（合格{$good}/返工{$rework}/报废{$scrap}）与投入 {$input} 不符";
            }
            if ($qtyIssues) {
                $record->check_status = 'qty_mismatch';
                $record->check_detail = implode('；', $qtyIssues);
                $record->save();
                $this->holdBatch($batch, Batch::HOLD_QTY, $record->check_detail, $record, $user, $equipment);
                return $record->fresh();
            }

            // 工艺参数核对
            if ($violations) {
                $detail = '参数超限：'.implode('；', $violations);
                $record->check_status = 'params_out_of_range';
                $record->check_detail = $detail;
                $record->save();
                $this->holdBatch($batch, Batch::HOLD_PARAMS, $detail, $record, $user, $equipment);
                return $record->fresh();
            }

            $record->check_status = 'normal';
            $record->save();

            $batch->update([
                'status' => Batch::QC_PENDING,
                'equipment_id' => $equipment->id,
                'current_record_id' => $record->id,
            ]);
            $equipment->update(['status' => 'running']);

            $this->trace->log($batch->order, $batch, 'production',
                $this->node($batch->station, $batch->process),
                $this->node($batch->station, $batch->process, $equipment),
                $input, [
                    'record_id' => $record->id,
                    'good' => $good, 'rework' => $rework, 'scrap' => $scrap,
                    'param_version' => $version->version,
                    'actual_params' => $actual,
                ], $user);

            return $record->fresh();
        });
    }

    /* ========================== 质检放行 ========================== */

    /**
     * 质检对登记批次作处置。守恒：passed + rework + scrap == 登记投入。
     * 操作员不能批准自己生产的批次；放行后生成在途转序单，其他工位不可占用。
     */
    public function inspect(ProductionRecord $record, User $user, array $data): QcInspection
    {
        return DB::transaction(function () use ($record, $user, $data) {
            $record = ProductionRecord::query()->lockForUpdate()->findOrFail($record->id);
            $batch = Batch::query()->lockForUpdate()->findOrFail($record->batch_id);

            if ($user->role !== 'qc') {
                throw new BusinessException('只有质检员可以执行质检放行');
            }
            if (! $user->is_active) {
                throw new BusinessException('账号已停用');
            }
            if ($record->operator_id === $user->id) {
                throw new BusinessException('操作员不能批准自己生产的批次：登记人与质检人必须分离');
            }
            if ($batch->status !== Batch::QC_PENDING) {
                throw new BusinessException("批次状态为 {$batch->status}，不在待质检状态");
            }
            if ($record->check_status === 'params_out_of_range') {
                throw new BusinessException('该登记存在工艺参数超限，须先由工艺工程师处置挂起');
            }

            $passed = (int) ($data['passed_qty'] ?? 0);
            $rework = (int) ($data['rework_qty'] ?? 0);
            $scrap = (int) ($data['scrap_qty'] ?? 0);
            if ($passed < 0 || $rework < 0 || $scrap < 0) {
                throw new BusinessException('数量不能为负数');
            }
            $total = $passed + $rework + $scrap;
            if ($total !== $record->input_qty) {
                throw new BusinessException(
                    "质检处置合计 {$total} 与登记投入 {$record->input_qty} 不一致，必须全部处置（放行/返工/报废）"
                );
            }

            $reworkToProcessId = $data['rework_to_process_id'] ?? null;
            if ($rework > 0 && ! $reworkToProcessId) {
                throw new BusinessException('存在返工数量时必须指定返工回流工序');
            }
            $reworkToProcess = $reworkToProcessId
                ? Process::where('product_code', $batch->product_code)->findOrFail($reworkToProcessId)
                : null;
            if ($reworkToProcess && $reworkToProcess->sequence > $record->process->sequence) {
                throw new BusinessException('返工工序不能位于当前工序之后');
            }

            $inspection = QcInspection::create([
                'production_record_id' => $record->id,
                'batch_id' => $batch->id,
                'inspector_id' => $user->id,
                'decision' => $passed > 0 ? QcInspection::PASSED : ($rework > 0 ? QcInspection::REJECTED : QcInspection::SCRAPPED),
                'rework_to_process_id' => $reworkToProcess?->id,
                'passed_qty' => $passed,
                'rework_qty' => $rework,
                'scrap_qty' => $scrap,
                'note' => $data['note'] ?? null,
            ]);

            // 报废分流（始终沿原生产单）
            if ($scrap > 0) {
                $this->branchScrap($batch, $scrap, $record, $inspection, $user);
            }
            // 返工回流分流（沿原生产单回到指定工序）
            if ($rework > 0) {
                $this->branchRework($batch, $rework, $record, $inspection, $reworkToProcess, $user);
            }

            // 放行部分由父批次继续流转
            if ($passed > 0) {
                $batch->update(['qty' => $passed]);
                $this->dispatchPassed($batch, $record, $passed, $user);
            } else {
                // 全部被分流：父批次成为零数量分流节点
                $batch->update(['qty' => 0, 'status' => Batch::SPLIT, 'equipment_id' => null]);
                $this->trace->log($batch->order, $batch, 'qc',
                    $this->node($batch->station, $record->process), null,
                    $total, ['inspection_id' => $inspection->id, 'fully_branched' => true], $user);
            }

            // 质检处置完成即离开本工位设备（放行在途/完工/分流均不再占用），释放设备
            \App\Models\Equipment::where('id', $record->equipment_id)->update(['status' => 'idle']);

            return $inspection->fresh();
        });
    }

    /** 放行后的去向：末道工序完工，否则发往下一工序（在途锁定） */
    private function dispatchPassed(Batch $batch, ProductionRecord $record, int $qty, User $user): void
    {
        $process = $record->process;
        $fromNode = $this->node($record->station, $process, $record->equipment);

        // 返工回流批次沿正常工艺路线重新前进；当再次越过发现缺陷的工序时关闭返工单
        $reworkOrder = $batch->origin === 'rework'
            ? ReworkOrder::where('rework_batch_id', $batch->id)->where('status', '!=', ReworkOrder::DONE)->latest('id')->first()
            : null;
        $next = $this->nextProcess($batch->product_code, $process->sequence);

        if (! $next || $process->is_final) {
            $batch->update(['status' => Batch::COMPLETED, 'equipment_id' => null]);
            $this->trace->log($batch->order, $batch, 'completed', $fromNode,
                $this->node(null, $process).' → 成品库', $qty,
                ['record_id' => $record->id, 'param_version' => $record->paramVersion->version], $user);
            if ($reworkOrder) {
                $reworkOrder->update(['status' => ReworkOrder::DONE]);
            }
            return;
        }

        $transfer = BatchTransfer::create([
            'production_order_id' => $batch->production_order_id,
            'batch_id' => $batch->id,
            'from_process_id' => $process->id,
            'from_station_id' => $record->station_id,
            'to_process_id' => $next->id,
            'to_station_id' => $next->station_id,
            'param_version_id' => $record->param_version_id, // 流转携带锁定版本
            'quantity' => $qty,
            'status' => BatchTransfer::PENDING,
            'released_by' => $user->id,
            'released_at' => now(),
        ]);

        $batch->update([
            'status' => Batch::RELEASED,
            'process_id' => $next->id,
            'station_id' => $next->station_id, // 预定工位（其他工位不能占用）
            'equipment_id' => null,
        ]);

        $this->trace->log($batch->order, $batch, 'transfer', $fromNode,
            $this->node($next->station, $next), $qty,
            ['transfer_id' => $transfer->id, 'carried_param_version' => $record->paramVersion->version], $user);

        // 已重新通过原缺陷工序（含）：返工闭环
        if ($reworkOrder && $process->sequence >= $reworkOrder->fromProcess->sequence) {
            $reworkOrder->update(['status' => ReworkOrder::DONE]);
        }
    }

    private function branchScrap(Batch $batch, int $qty, ProductionRecord $record, QcInspection $inspection, User $user): void
    {
        $child = $this->createChildBatch($batch, 'scrap', $qty, [
            'status' => Batch::SCRAPPED,
            'station_id' => $batch->station_id,
            'equipment_id' => null,
        ]);
        ScrapRecord::create([
            'production_order_id' => $batch->production_order_id,
            'batch_id' => $child->id,
            'production_record_id' => $record->id,
            'quantity' => $qty,
            'reason' => '质检判废'.($inspection->note ? '：'.$inspection->note : ''),
            'created_by' => $user->id,
        ]);
        $this->trace->log($batch->order, $child, 'scrap',
            $this->node($batch->station, $record->process), '报废',
            $qty, ['parent_batch_id' => $batch->id, 'inspection_id' => $inspection->id,
                   'param_version' => $record->paramVersion->version], $user);
    }

    private function branchRework(Batch $batch, int $qty, ProductionRecord $record, QcInspection $inspection, Process $toProcess, User $user): ReworkOrder
    {
        $child = $this->createChildBatch($batch, 'rework', $qty, [
            'status' => Batch::QUEUED,
            'process_id' => $toProcess->id,
            'station_id' => null,        // 在回流工序排队，等待该工位接收
            'equipment_id' => null,
            'param_version_id' => null, // 由回流工序接收时重新锁定该工序的生效版本
        ]);

        $order = ReworkOrder::create([
            'production_order_id' => $batch->production_order_id,
            'qc_inspection_id' => $inspection->id,
            'production_record_id' => $record->id,
            'source_batch_id' => $batch->id,
            'rework_batch_id' => $child->id,
            'from_process_id' => $record->process_id,
            'to_process_id' => $toProcess->id,
            'quantity' => $qty,
            'status' => ReworkOrder::OPEN,
            'created_by' => $user->id,
        ]);

        $this->trace->log($batch->order, $child, 'rework_created',
            $this->node($batch->station, $record->process),
            $this->node($toProcess->station, $toProcess),
            $qty, ['parent_batch_id' => $batch->id, 'rework_order_id' => $order->id,
                   'inspection_id' => $inspection->id], $user);

        return $order;
    }

    /* ========================== 批次拆分 ========================== */

    /**
     * 拆分：只能在当前工序开工前进行（本工序尚无登记），数量守恒。
     */
    public function split(Batch $batch, User $user, array $quantities): array
    {
        return DB::transaction(function () use ($batch, $user, $quantities) {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, [Batch::QUEUED, Batch::IN_PROGRESS], true)) {
                throw new BusinessException("批次状态为 {$batch->status}，当前不能拆分");
            }
            $exists = ProductionRecord::where('batch_id', $batch->id)
                ->where('process_id', $batch->process_id)->exists();
            if ($exists) {
                throw new BusinessException('批次在当前工序已登记产出，不能再拆分（应在开工前拆分）');
            }

            $quantities = array_values(array_map('intval', $quantities));
            if (count($quantities) < 2) {
                throw new BusinessException('拆分至少需要两个子数量');
            }
            foreach ($quantities as $q) {
                if ($q <= 0) {
                    throw new BusinessException('拆分数量必须为正整数');
                }
            }
            if (array_sum($quantities) !== (int) $batch->qty) {
                throw new BusinessException(
                    "拆分数量合计 ".array_sum($quantities)." 与原批次数量 {$batch->qty} 不一致"
                );
            }

            $wasInProgress = $batch->status === Batch::IN_PROGRESS;
            $children = [];
            foreach ($quantities as $q) {
                $child = $this->createChildBatch($batch, 'split', $q, [
                    'status' => $wasInProgress ? Batch::IN_PROGRESS : Batch::QUEUED,
                    'station_id' => $batch->station_id,
                    'process_id' => $batch->process_id,
                    'equipment_id' => null,
                    'param_version_id' => $batch->param_version_id, // 同批材料沿用锁定版本
                ]);
                SplitRecord::create([
                    'production_order_id' => $batch->production_order_id,
                    'parent_batch_id' => $batch->id,
                    'child_batch_id' => $child->id,
                    'quantity' => $q,
                    'created_by' => $user->id,
                ]);
                $this->trace->log($batch->order, $child, 'split',
                    $batch->batch_no, $child->batch_no, $q,
                    ['parent_batch_id' => $batch->id, 'split_record' => true], $user);
                $children[] = $child;
            }

            $batch->update(['qty' => 0, 'status' => Batch::SPLIT, 'equipment_id' => null]);
            return $children;
        });
    }

    /* ====================== 开工前/挂起报废 ====================== */

    /**
     * 开工前/挂起报废：从父批分流一个判废子批，父批保留剩余数量（守恒）
     */
    public function scrap(Batch $batch, User $user, int $quantity, ?string $reason = null): Batch
    {
        return DB::transaction(function () use ($batch, $user, $quantity, $reason) {
            $batch = Batch::query()->lockForUpdate()->findOrFail($batch->id);
            if (! in_array($batch->status, [Batch::QUEUED, Batch::IN_PROGRESS, Batch::ON_HOLD], true)) {
                throw new BusinessException("批次状态为 {$batch->status}，不能直接报废");
            }
            $quantity = (int) $quantity;
            if ($quantity <= 0 || $quantity > $batch->qty) {
                throw new BusinessException("报废数量须在 1..{$batch->qty} 之间");
            }

            $holdReason = $batch->on_hold_reason;
            $child = $this->createChildBatch($batch, 'scrap', $quantity, [
                'status' => Batch::SCRAPPED,
                'station_id' => $batch->station_id,
                'equipment_id' => null,
            ]);
            ScrapRecord::create([
                'production_order_id' => $batch->production_order_id,
                'batch_id' => $child->id,
                'production_record_id' => $batch->current_record_id,
                'quantity' => $quantity,
                'reason' => $reason,
                'created_by' => $user->id,
            ]);

            $remaining = (int) $batch->qty - $quantity;
            if ($remaining === 0) {
                $newStatus = Batch::SPLIT;
            } elseif ($batch->status === Batch::ON_HOLD) {
                $newStatus = Batch::ON_HOLD;
            } else {
                $newStatus = $batch->status;
            }
            $batch->update([
                'qty' => $remaining,
                'status' => $newStatus,
                'on_hold_reason' => $remaining === 0 ? null : $holdReason,
                'equipment_id' => $remaining === 0 ? null : $batch->equipment_id,
            ]);

            $this->trace->log($batch->order, $child, 'scrap',
                $this->node($batch->station, $batch->process), '报废',
                $quantity, ['parent_batch_id' => $batch->id, 'remaining' => $remaining,
                             'reason' => $reason], $user);

            return $batch->fresh();
        });
    }

    /* ========================== 挂起处置 ========================== */

    /**
     * 处置待处理批次：
     * - 数量不符：correct 更正登记数（留痕）；shortfall_as_scrap 短缺计报废
     * - 参数超限：waive 让步放行（工艺工程师）；rework 整批回流；scrap 整批判废
     */
    public function resolveHold(ProductionRecord $record, User $user, string $action, array $data = []): Batch
    {
        return DB::transaction(function () use ($record, $user, $action, $data) {
            $record = ProductionRecord::query()->lockForUpdate()->findOrFail($record->id);
            $batch = Batch::query()->lockForUpdate()->findOrFail($record->batch_id);
            if ($batch->status !== Batch::ON_HOLD) {
                throw new BusinessException('批次不在待处理状态');
            }

            switch ($action) {
                case 'correct':
                    if ($record->check_status !== 'qty_mismatch') {
                        throw new BusinessException('更正登记仅适用于数量不符的挂起');
                    }
                    $good = (int) ($data['good_qty'] ?? $record->good_qty);
                    $rework = (int) ($data['rework_qty'] ?? $record->rework_qty);
                    $scrap = (int) ($data['scrap_qty'] ?? $record->scrap_qty);
                    if ($good + $rework + $scrap !== $record->input_qty) {
                        throw new BusinessException(
                            "更正后合计 ".($good + $rework + $scrap)." 仍与投入 {$record->input_qty} 不符"
                        );
                    }
                    $old = ['good' => $record->good_qty, 'rework' => $record->rework_qty, 'scrap' => $record->scrap_qty];
                    $record->update([
                        'good_qty' => $good, 'rework_qty' => $rework, 'scrap_qty' => $scrap,
                        'check_status' => 'normal', 'check_detail' => null,
                    ]);
                    $this->trace->log($batch->order, $batch, 'hold_resolved',
                        '待处理', '待质检', $record->input_qty,
                        ['action' => 'correct', 'old' => $old,
                         'new' => ['good' => $good, 'rework' => $rework, 'scrap' => $scrap]], $user);
                    $batch->update(['status' => Batch::QC_PENDING, 'on_hold_reason' => null]);
                    break;

                case 'shortfall_as_scrap':
                    if ($record->check_status !== 'qty_mismatch') {
                        throw new BusinessException('短缺计报废仅适用于数量不符的挂起');
                    }
                    if ($user->role !== 'engineer' && $user->role !== 'qc' && $user->role !== 'admin') {
                        throw new BusinessException('只有工艺工程师/质检员可以裁定短缺计报废');
                    }
                    $shortfall = $record->input_qty - $record->accountedQty();
                    if ($shortfall <= 0) {
                        throw new BusinessException('登记合计不小于投入，不存在短缺');
                    }
                    // 短缺并入登记的报废数；判废子批在后续质检处置时统一分流（避免重复记账）
                    $record->update(['check_status' => 'normal', 'check_detail' => null,
                        'scrap_qty' => $record->scrap_qty + $shortfall]);
                    $this->trace->log($batch->order, $batch, 'hold_resolved',
                        '待处理', '待质检', $shortfall,
                        ['action' => 'shortfall_as_scrap', 'shortfall' => $shortfall,
                         'note' => '短缺计报废，待质检分流判废子批'], $user);
                    $batch->update(['status' => Batch::QC_PENDING, 'on_hold_reason' => null]);
                    break;

                case 'waive':
                    $this->assertEngineer($user);
                    if ($record->check_status !== 'params_out_of_range') {
                        throw new BusinessException('让步放行仅适用于参数超限挂起');
                    }
                    if (empty($data['note'])) {
                        throw new BusinessException('让步放行必须填写处置说明（留痕）');
                    }
                    // 超限事实永久保留在 record 上，仅解除流转挂起
                    $record->update(['check_status' => 'waived']);
                    $this->trace->log($batch->order, $batch, 'hold_resolved',
                        '待处理', '待质检', $record->input_qty,
                        ['action' => 'waive', 'note' => $data['note'],
                         'violations' => $record->check_detail,
                         'actual_params' => $record->actual_params], $user);
                    $batch->update(['status' => Batch::QC_PENDING, 'on_hold_reason' => null]);
                    break;

                case 'rework':
                    $this->assertEngineer($user);
                    if ($record->check_status !== 'params_out_of_range') {
                        throw new BusinessException('超限返工仅适用于参数超限挂起');
                    }
                    $toProcess = Process::where('product_code', $batch->product_code)
                        ->findOrFail($data['rework_to_process_id'] ?? 0);
                    if ($toProcess->sequence > $record->process->sequence) {
                        throw new BusinessException('返工工序不能位于当前工序之后');
                    }
                    $child = $this->createChildBatch($batch, 'rework', $record->input_qty, [
                        'status' => Batch::QUEUED,
                        'process_id' => $toProcess->id,
                        'station_id' => null,
                        'equipment_id' => null,
                        'param_version_id' => null,
                    ]);
                    $rw = ReworkOrder::create([
                        'production_order_id' => $batch->production_order_id,
                        'production_record_id' => $record->id,
                        'source_batch_id' => $batch->id,
                        'rework_batch_id' => $child->id,
                        'from_process_id' => $record->process_id,
                        'to_process_id' => $toProcess->id,
                        'quantity' => $record->input_qty,
                        'status' => ReworkOrder::OPEN,
                        'created_by' => $user->id,
                    ]);
                    $batch->update(['qty' => 0, 'status' => Batch::SPLIT, 'equipment_id' => null,
                        'on_hold_reason' => null]);
                    $this->trace->log($batch->order, $child, 'rework_created',
                        '待处理（参数超限）', $this->node($toProcess->station, $toProcess),
                        $record->input_qty, ['rework_order_id' => $rw->id, 'hold_resolved' => true], $user);
                    break;

                case 'scrap':
                    $this->assertEngineer($user);
                    $child = $this->createChildBatch($batch, 'scrap', $record->input_qty, [
                        'status' => Batch::SCRAPPED,
                        'station_id' => $batch->station_id,
                        'process_id' => $batch->process_id,
                        'equipment_id' => null,
                    ]);
                    ScrapRecord::create([
                        'production_order_id' => $batch->production_order_id,
                        'batch_id' => $child->id,
                        'production_record_id' => $record->id,
                        'quantity' => $record->input_qty,
                        'reason' => '挂起判废：'.($data['note'] ?? $record->check_detail ?? ''),
                        'created_by' => $user->id,
                    ]);
                    $this->trace->log($batch->order, $child, 'scrap',
                        '待处理', '报废', $record->input_qty,
                        ['action' => 'scrap', 'hold_reason' => $batch->on_hold_reason,
                         'parent_batch_id' => $batch->id], $user);
                    $batch->update(['qty' => 0, 'status' => Batch::SPLIT,
                        'on_hold_reason' => null, 'equipment_id' => null]);
                    break;

                default:
                    throw new BusinessException("未知的挂起处置动作：{$action}");
            }

            return $batch->fresh();
        });
    }

    /* ========================== 内部工具 ========================== */

    private function holdBatch(Batch $batch, string $reason, string $detail, ProductionRecord $record, User $user, Equipment $equipment): void
    {
        $batch->update([
            'status' => Batch::ON_HOLD,
            'on_hold_reason' => $reason,
            'equipment_id' => $equipment->id,
            'current_record_id' => $record->id,
        ]);
        $this->trace->log($batch->order, $batch, 'hold',
            $this->node($batch->station, $batch->process, $equipment), '待处理',
            $record->input_qty, ['reason' => $reason, 'detail' => $detail,
                                 'record_id' => $record->id,
                                 'actual_params' => $record->actual_params], $user);
    }

    /**
     * 创建子批次（拆分/返工/判废分流），所有子批次始终挂在同一生产单与同一根批次下
     */
    private function createChildBatch(Batch $parent, string $origin, int $qty, array $overrides = []): Batch
    {
        $siblingCount = Batch::where('parent_batch_id', $parent->id)->count();
        $suffix = match ($origin) {
            'rework' => 'R',
            'scrap' => 'X',
            default => 'S',
        };
        $batchNo = sprintf('%s-%s%d', $parent->batch_no, $suffix, $siblingCount + 1);

        return Batch::create(array_merge([
            'batch_no' => $batchNo,
            'production_order_id' => $parent->production_order_id,
            'root_batch_id' => $parent->root_batch_id ?? $parent->id,
            'parent_batch_id' => $parent->id,
            'origin' => $origin,
            'depth' => $parent->depth + 1,
            'product_code' => $parent->product_code,
            'process_id' => $parent->process_id,
            'station_id' => $parent->station_id,
            'equipment_id' => null,
            'param_version_id' => $parent->param_version_id,
            'qty' => $qty,
            'status' => Batch::QUEUED,
            'created_by' => $parent->created_by,
        ], $overrides));
    }

    private function checkParamRanges(ParameterVersion $version, array $actual): array
    {
        $violations = [];
        foreach ($version->items as $item) {
            $code = $item->template->code;
            if (! array_key_exists($code, $actual)) {
                $violations[] = sprintf('%s(%s) 缺少实测读数', $item->template->name, $code);
                continue;
            }
            $v = (float) $actual[$code];
            if ($v < (float) $item->min_value || $v > (float) $item->max_value) {
                $violations[] = sprintf(
                    '%s(%s)=%s 超出版本%s范围 [%s, %s] %s',
                    $item->template->name, $code, $v, $version->version,
                    $item->min_value, $item->max_value, $item->template->unit ?: ''
                );
            }
        }
        return $violations;
    }

    private function normalizeParams(mixed $params): array
    {
        if (is_object($params)) {
            $params = (array) $params;
        }
        if (! is_array($params)) {
            throw new BusinessException('工艺参数必须是键值对象');
        }
        $out = [];
        foreach ($params as $code => $value) {
            if (! is_numeric($value)) {
                throw new BusinessException("参数 {$code} 的值必须是数值");
            }
            $out[$code] = (float) $value;
        }
        return $out;
    }

    public function nextProcess(string $productCode, int $sequence): ?Process
    {
        return Process::where('product_code', $productCode)
            ->where('is_active', true)
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')->first();
    }

    private function genOrderNo(): string
    {
        $prefix = 'MO-'.now()->format('Ymd').'-';
        $n = ProductionOrder::where('order_no', 'like', $prefix.'%')->count() + 1;
        return $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }

    private function node(?\App\Models\Station $station, ?Process $process, ?Equipment $equipment = null): ?string
    {
        return TraceService::node($station, $process, $equipment);
    }

    private function assertActiveOperator(User $user): void
    {
        if ($user->role !== 'operator') {
            throw new BusinessException('只有工位操作员可以执行该操作');
        }
        if (! $user->is_active) {
            throw new BusinessException('账号已停用');
        }
    }

    private function assertEngineer(User $user): void
    {
        if (! in_array($user->role, ['engineer', 'admin'], true)) {
            throw new BusinessException('只有工艺工程师可以执行该操作');
        }
    }
}
