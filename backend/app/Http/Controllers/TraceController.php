<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\BatchTransfer;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\ProductionRecord;
use App\Models\ReworkOrder;
use App\Models\ScrapRecord;
use App\Models\SplitRecord;
use App\Models\TraceEvent;
use Illuminate\Http\Request;

class TraceController extends Controller
{
    /** 生产单完整追溯：事件时间线 + 批次树 + 数量守恒核对 */
    public function orderTrace(ProductionOrder $productionOrder)
    {
        $order = $productionOrder;
        $processes = Process::where('product_code', $order->product_code)
            ->where('is_active', true)->orderBy('sequence')->get();

        $events = TraceEvent::with(['actor:id,name', 'batch:id,batch_no'])
            ->where('production_order_id', $order->id)
            ->orderBy('occurred_at')->orderBy('id')->get();

        $batches = Batch::with([
            'process:id,code,name,sequence', 'station:id,code,name',
            'equipment:id,code,name', 'paramVersion:id,version',
            'parentBatch:id,batch_no',
        ])->where('production_order_id', $order->id)->orderBy('id')->get();

        $records = ProductionRecord::with(['process:id,code,sequence', 'equipment:id,code',
            'operator:id,name', 'paramVersion:id,version', 'inspections.inspector:id,name'])
            ->whereIn('batch_id', $batches->pluck('id'))->orderBy('id')->get();

        $scraps = ScrapRecord::where('production_order_id', $order->id)->orderBy('id')->get();
        $splits = SplitRecord::where('production_order_id', $order->id)->orderBy('id')->get();
        $reworks = ReworkOrder::with(['sourceBatch:id,batch_no', 'reworkBatch:id,batch_no',
            'fromProcess:id,code', 'toProcess:id,code'])
            ->where('production_order_id', $order->id)->orderBy('id')->get();
        $transfers = BatchTransfer::with(['fromProcess:id,code', 'toProcess:id,code',
            'fromStation:id,code', 'toStation:id,code'])
            ->where('production_order_id', $order->id)->orderBy('id')->get();

        // 树构建
        $byParent = $batches->groupBy(fn ($b) => $b->parent_batch_id ?? 0);
        $tree = [];
        foreach ($byParent->get(0, collect()) as $root) {
            $tree[] = $this->batchNode($root, $byParent, $records, $reworks);
        }

        // 数量守恒核对
        $reconciliation = $this->reconcile($order, $batches, $records, $scraps, $reworks);

        return response()->json([
            'order' => $order->only('id', 'order_no', 'product_code', 'product_name', 'planned_qty', 'status'),
            'process_route' => $processes->map(fn ($p) => [
                'sequence' => $p->sequence, 'code' => $p->code, 'name' => $p->name,
                'station' => $p->station->only('code', 'name'), 'is_final' => $p->is_final,
            ]),
            'tree' => $tree,
            'events' => $events->map(fn ($e) => [
                'id' => $e->id,
                'event_type' => $e->event_type,
                'batch_no' => $e->batch?->batch_no,
                'from_node' => $e->from_node,
                'to_node' => $e->to_node,
                'quantity' => $e->quantity,
                'payload' => $e->payload,
                'actor' => $e->actor?->name,
                'occurred_at' => $e->occurred_at->toDateTimeString(),
            ]),
            'splits' => $splits,
            'rework_orders' => $reworks,
            'transfers' => $transfers,
            'reconciliation' => $reconciliation,
        ]);
    }

    private function batchNode($batch, $byParent, $records, $reworks): array
    {
        $batchRecords = $records->where('batch_id', $batch->id)->values();
        $reworkIn = $reworks->firstWhere('rework_batch_id', $batch->id);
        return [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'origin' => $batch->origin,
            'depth' => $batch->depth,
            'qty' => $batch->qty,
            'status' => $batch->status,
            'on_hold_reason' => $batch->on_hold_reason,
            'location' => [
                'process' => $batch->process?->only('code', 'name', 'sequence'),
                'station' => $batch->station?->only('code', 'name'),
                'equipment' => $batch->equipment?->only('code', 'name'),
                'param_version' => $batch->paramVersion?->version,
            ],
            'rework_of' => $reworkIn ? [
                'source_batch_no' => $reworkIn->sourceBatch->batch_no,
                'from_process' => $reworkIn->fromProcess->code,
                'to_process' => $reworkIn->toProcess->code,
                'status' => $reworkIn->status,
            ] : null,
            'records' => $batchRecords->map(fn ($r) => [
                'id' => $r->id,
                'process' => $r->process->code,
                'equipment' => $r->equipment->code,
                'operator' => $r->operator->name,
                'param_version' => $r->paramVersion->version,
                'input_qty' => $r->input_qty,
                'good_qty' => $r->good_qty,
                'rework_qty' => $r->rework_qty,
                'scrap_qty' => $r->scrap_qty,
                'check_status' => $r->check_status,
                'check_detail' => $r->check_detail,
                'actual_params' => $r->actual_params,
                'inspections' => $r->inspections->map(fn ($i) => [
                    'decision' => $i->decision,
                    'inspector' => $i->inspector->name,
                    'passed_qty' => $i->passed_qty,
                    'rework_qty' => $i->rework_qty,
                    'scrap_qty' => $i->scrap_qty,
                ]),
            ]),
            'children' => $byParent->get($batch->id, collect())
                ->map(fn ($c) => $this->batchNode($c, $byParent, $records, $reworks))->values(),
        ];
    }

    /**
     * 数量守恒核对：
     * 计划数量 == 现存有效批次数量 + 累计报废数量
     * 每条登记：投入 == 合格 + 返工 + 报废
     * 每次拆分：父数量 == 子数量之和
     */
    private function reconcile($order, $batches, $records, $scraps, $reworks): array
    {
        $checks = [];

        $live = (int) $batches->whereNotIn('status', [Batch::SPLIT, Batch::SCRAPPED])->sum('qty');
        $scrappedBatches = (int) $batches->where('status', Batch::SCRAPPED)->sum('qty');
        $scrapLedger = (int) $scraps->sum('quantity');
        $completed = (int) $batches->where('status', Batch::COMPLETED)->sum('qty');
        $wip = (int) $batches->whereNotIn('status',
            [Batch::SPLIT, Batch::SCRAPPED, Batch::COMPLETED])->sum('qty');

        $checks[] = [
            'name' => '总量守恒：计划 = 在制 + 完工 + 报废',
            'detail' => sprintf('计划 %d = 在制 %d + 完工 %d + 报废 %d',
                $order->planned_qty, $wip, $completed, $scrappedBatches),
            'pass' => (int) $order->planned_qty === $wip + $completed + $scrappedBatches,
        ];
        $checks[] = [
            'name' => '报废台账与报废批次数量一致',
            'detail' => "报废台账 {$scrapLedger}，报废批次合计 {$scrappedBatches}",
            'pass' => $scrapLedger === $scrappedBatches,
        ];

        foreach ($records as $r) {
            $sum = $r->good_qty + $r->rework_qty + $r->scrap_qty;
            $checks[] = [
                'name' => "登记 #{$r->id}（批次{$r->batch_id} {$r->process->code}）数量核对",
                'detail' => "投入 {$r->input_qty} = 合格 {$r->good_qty} + 返工 {$r->rework_qty} + 报废 {$r->scrap_qty}",
                'pass' => $sum === (int) $r->input_qty,
            ];
        }

        $splitParents = $batches->where('status', Batch::SPLIT);
        foreach ($splitParents as $parent) {
            $children = $batches->where('parent_batch_id', $parent->id);
            $sum = (int) $children->sum('qty') + (int) $scraps->where('batch_id', $parent->id)->sum('quantity');
            // 拆分时刻：父批次原数量应等于现存子数量+已从子判废数量；用 split_records 精确核对
            $splitSum = (int) \App\Models\SplitRecord::where('parent_batch_id', $parent->id)->sum('quantity');
            $childrenAll = (int) $children->sum('qty');
            $childScrap = (int) $scraps->whereIn('batch_id', $children->pluck('id'))->sum('quantity');
            $checks[] = [
                'name' => "拆分 {$parent->batch_no} 数量守恒",
                'detail' => "拆分出 {$splitSum}，子批次现存 {$childrenAll} + 子批次报废 {$childScrap} = "
                    .($childrenAll + $childScrap),
                'pass' => $splitSum === $childrenAll + $childScrap,
            ];
        }

        foreach ($reworks as $rw) {
            $child = $batches->firstWhere('id', $rw->rework_batch_id);
            $childScrap = (int) $scraps->where('batch_id', $child?->id)->sum('quantity');
            $checks[] = [
                'name' => "返工回流 #{$rw->id}（{$rw->sourceBatch->batch_no} → {$rw->reworkBatch->batch_no}）数量守恒",
                'detail' => "返工量 {$rw->quantity}，回流批次现存 {$child?->qty} + 报废 {$childScrap} = "
                    .(($child?->qty ?? 0) + $childScrap),
                'pass' => $rw->quantity === (int) ($child?->qty ?? 0) + $childScrap,
            ];
        }

        return [
            'all_pass' => collect($checks)->every(fn ($c) => $c['pass']),
            'totals' => [
                'planned' => (int) $order->planned_qty,
                'wip' => $wip,
                'completed' => $completed,
                'scrapped' => $scrappedBatches,
                'scrap_ledger' => $scrapLedger,
                'live' => $live,
            ],
            'checks' => $checks,
        ];
    }

    /** 单批次工序图：当前所在设备/工序/参数 + 已完成路线 */
    public function batchRoute(Batch $batch)
    {
        $batch->loadMissing(['process', 'station', 'equipment', 'paramVersion.items.template',
            'order', 'records.process', 'transfers' => fn ($q) => $q->orderBy('id')]);

        $processes = Process::where('product_code', $batch->product_code)
            ->where('is_active', true)->orderBy('sequence')->get();

        $recordedProcessIds = $batch->records->pluck('process_id')->all();
        $pendingTransfer = $batch->transfers->where('status', BatchTransfer::PENDING)->last();

        $steps = $processes->map(function ($p) use ($batch, $recordedProcessIds, $pendingTransfer) {
            $state = 'pending';
            if (in_array($p->id, $recordedProcessIds, true)) {
                $state = 'done';
            }
            if ($batch->process_id === $p->id) {
                $state = match ($batch->status) {
                    Batch::QUEUED => 'waiting',
                    Batch::RELEASED => 'in_transit',
                    Batch::IN_PROGRESS => 'running',
                    Batch::QC_PENDING => 'qc_pending',
                    Batch::ON_HOLD => 'on_hold',
                    Batch::COMPLETED, Batch::REWORK_DONE => 'done',
                    default => $state,
                };
            }
            return [
                'sequence' => $p->sequence,
                'code' => $p->code,
                'name' => $p->name,
                'station_code' => $p->station->code,
                'state' => $state,
                'is_current' => $batch->process_id === $p->id,
                'param_version' => ($batch->process_id === $p->id) ? $batch->paramVersion?->version : null,
                'actual_params' => ($batch->process_id === $p->id)
                    ? $batch->records->where('process_id', $p->id)->last()?->actual_params
                    : $batch->records->firstWhere('process_id', $p->id)?->actual_params,
            ];
        });

        return response()->json([
            'batch' => $batch->only('id', 'batch_no', 'qty', 'status', 'on_hold_reason', 'origin'),
            'current' => [
                'process' => $batch->process?->only('code', 'name', 'sequence'),
                'station' => $batch->station?->only('code', 'name'),
                'equipment' => $batch->equipment?->only('code', 'name'),
                'param_version' => $batch->paramVersion?->only('id', 'version', 'status'),
                'param_items' => $batch->paramVersion?->items->map(fn ($i) => [
                    'code' => $i->template->code,
                    'name' => $i->template->name,
                    'unit' => $i->template->unit,
                    'set_value' => (float) $i->set_value,
                    'min_value' => (float) $i->min_value,
                    'max_value' => (float) $i->max_value,
                ]),
            ],
            'pending_transfer' => $pendingTransfer ? [
                'to_station' => $pendingTransfer->toStation->code,
                'to_process' => $pendingTransfer->toProcess->code,
                'carried_param_version' => $pendingTransfer->paramVersion->version,
            ] : null,
            'route' => $steps,
        ]);
    }

    /** 待处理（挂起）批次列表 */
    public function holds(Request $request)
    {
        return \App\Models\ProductionRecord::with([
            'batch:id,batch_no,qty,status,on_hold_reason,production_order_id,process_id,station_id,equipment_id',
            'batch.order:id,order_no,product_code,product_name',
            'batch.process:id,code,name', 'batch.station:id,code,name',
            'process:id,code,name', 'equipment:id,code,name', 'operator:id,name',
            'paramVersion:id,version',
        ])->whereIn('check_status', ['qty_mismatch', 'params_out_of_range'])
            ->whereHas('batch', fn ($q) => $q->where('status', Batch::ON_HOLD))
            ->orderByDesc('id')->get();
    }
}
