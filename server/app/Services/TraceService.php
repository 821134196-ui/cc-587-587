<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchEvent;
use Illuminate\Support\Collection;

/**
 * 追溯链:沿原生产单汇总根批次、拆分子批、返工子批的全部事件,
 * 并做全链数量核对(投入 = 在手 + 已完成合格 + 累计报废)。
 */
class TraceService
{
    public function trace(Batch $batch): array
    {
        $order = $batch->order;
        /** @var Collection<int, Batch> $batches */
        $batches = Batch::with(['process', 'equipment', 'parameterVersion', 'parent'])
            ->where('production_order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $events = BatchEvent::with(['actor', 'fromProcess', 'toProcess', 'fromEquipment', 'toEquipment', 'parameterVersion'])
            ->where('production_order_id', $order->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'order' => $order,
            'root_batch_id' => $this->rootOf($batch, $batches)?->id,
            'tree' => $this->buildTree($batches),
            'batches' => $batches->values(),
            'events' => $events,
            'reconciliation' => $this->reconcile($batches, $events),
        ];
    }

    /**
     * 全链数量核对。
     *
     * 投入(根批次 qty_initial 之和)必须等于:
     *   各批次在手数量(按状态折算) + 已完成批次的最终合格数 + 全部产出事件累计报废数
     *
     * 在手折算规则:
     *   in_production / on_hold : qty_received(尚未有效登记产出,数量原样在手)
     *   pending_qc / released   : qty_qualified + qty_rework(报废已计入事件,不再重复)
     *   completed               : qty_qualified(最终合格入库)
     *   split                   : 0(数量已全部转入子批,由子批各自统计)
     */
    public function reconcile(Collection $batches, Collection $events): array
    {
        $roots = $batches->where('origin', Batch::ORIGIN_ROOT);
        $totalIn = (int) $roots->sum('qty_initial');

        $inHand = 0;
        $completedGood = 0;
        $perBatch = [];

        foreach ($batches as $b) {
            $held = match ($b->status) {
                Batch::STATUS_IN_PRODUCTION, Batch::STATUS_ON_HOLD => $b->qty_received,
                Batch::STATUS_PENDING_QC, Batch::STATUS_RELEASED => $b->qty_qualified + $b->qty_rework,
                Batch::STATUS_COMPLETED => 0,
                Batch::STATUS_SPLIT => 0,
                default => 0,
            };
            if ($b->status === Batch::STATUS_COMPLETED) {
                $completedGood += $b->qty_qualified;
            }
            $inHand += $held;

            // 单批核对:已登记产出的批次,投入必须等于 合格+返工+报废
            $lineCheck = null;
            if (in_array($b->status, [Batch::STATUS_PENDING_QC, Batch::STATUS_RELEASED, Batch::STATUS_COMPLETED], true)) {
                $lineCheck = $b->qty_received === $b->qty_qualified + $b->qty_rework + $b->qty_scrap
                    || $this->reworkDrained($b, $batches);
            }
            // 已拆分批次:父批在手必须等于各拆分子批初始之和
            // (子批继续流转后 qty_received 会变,守恒对照用创建时的 qty_initial)
            if ($b->status === Batch::STATUS_SPLIT) {
                $splitChildren = $batches->where('parent_batch_id', $b->id)->where('origin', Batch::ORIGIN_SPLIT);
                $lineCheck = (int) $splitChildren->sum('qty_initial') === $b->qty_received;
            }

            $perBatch[] = [
                'batch_id' => $b->id,
                'code' => $b->code,
                'status' => $b->status,
                'in_hand' => $held,
                'balanced' => $lineCheck,
            ];
        }

        $scrapped = (int) $events->where('type', BatchEvent::TYPE_OUTPUT)->sum('qty_scrap');
        $accounted = $inHand + $completedGood + $scrapped;

        return [
            'total_in' => $totalIn,
            'in_hand' => $inHand,
            'completed_good' => $completedGood,
            'scrapped' => $scrapped,
            'accounted' => $accounted,
            'balanced' => $accounted === $totalIn,
            'per_batch' => $perBatch,
        ];
    }

    /**
     * 返工已部分转出的批次:剩余返工 + 已转出返工子批 = 登记返工数,
     * 此时 qty_received = 合格 + 剩余返工 + 报废 + 已转出返工。
     */
    private function reworkDrained(Batch $batch, Collection $batches): bool
    {
        $reworkedOut = (int) $batches
            ->where('parent_batch_id', $batch->id)
            ->where('origin', Batch::ORIGIN_REWORK)
            ->sum('qty_initial');

        return $batch->qty_received === $batch->qty_qualified + $batch->qty_rework + $batch->qty_scrap + $reworkedOut;
    }

    private function rootOf(Batch $batch, Collection $batches): ?Batch
    {
        $current = $batch;
        $guard = 0;
        while ($current->parent_batch_id !== null && $guard++ < 100) {
            $current = $batches->get($current->parent_batch_id) ?? $current->parent;
            if ($current === null) {
                return null;
            }
        }

        return $current;
    }

    /**
     * 批次树:每个节点带基本信息与子节点(拆分/返工)。
     */
    private function buildTree(Collection $batches): array
    {
        $makeNode = function (Batch $b) use (&$makeNode, $batches) {
            return [
                'id' => $b->id,
                'code' => $b->code,
                'origin' => $b->origin,
                'status' => $b->status,
                'qty_initial' => $b->qty_initial,
                'qty_received' => $b->qty_received,
                'process' => $b->process?->name,
                'equipment' => $b->equipment?->name,
                'parameter_version' => $b->parameterVersion ? 'v'.$b->parameterVersion->version : null,
                'children' => $batches->where('parent_batch_id', $b->id)
                    ->sortBy('id')
                    ->map($makeNode)
                    ->values()
                    ->all(),
            ];
        };

        return $batches->whereNull('parent_batch_id')
            ->sortBy('id')
            ->map($makeNode)
            ->values()
            ->all();
    }
}
