<?php

namespace App\Http\Controllers;

use App\Models\Batch;
use App\Models\ProductionRecord;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function __construct(private readonly ProductionService $service) {}

    public function index(Request $request)
    {
        $q = Batch::with([
            'order:id,order_no,product_code,product_name',
            'process:id,code,name,sequence',
            'station:id,code,name',
            'equipment:id,code,name',
            'paramVersion:id,version',
            'parentBatch:id,batch_no',
        ]);

        if ($request->query('production_order_id')) {
            $q->where('production_order_id', (int) $request->query('production_order_id'));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        $stationId = null;
        if ($request->boolean('my_station')) {
            $stationId = $request->user()->station_id;
        } elseif ($request->query('station_id')) {
            $stationId = (int) $request->query('station_id');
        }
        if (! empty($stationId)) {
            // 本工位可见：当前在本工位、预定到本工位、或在本工位排队
            $q->where(function ($w) use ($stationId) {
                $w->where('station_id', $stationId)
                  ->orWhereHas('transfers', fn ($t) => $t->where('status', 'pending')->where('to_station_id', $stationId))
                  ->orWhere(fn ($q) => $q->where('status', \App\Models\Batch::QUEUED)
                      ->whereHas('process', fn ($p) => $p->where('station_id', $stationId)));
            });
        }
        if ($root = $request->query('root_batch_id')) {
            $q->where('root_batch_id', $root);
        }
        if ($request->boolean('active_only')) {
            $q->whereNotIn('status', [Batch::SPLIT]);
        }

        return $q->orderByDesc('id')->get();
    }

    public function show(Batch $batch)
    {
        return $batch->load([
            'order', 'process.station', 'station', 'equipment', 'paramVersion.items.template',
            'parentBatch:id,batch_no,batch_no', 'rootBatch:id,batch_no',
            'children:id,batch_no,parent_batch_id,origin,qty,status',
            'records' => fn ($q) => $q->with(['operator:id,name', 'equipment:id,code', 'process:id,code,name,sequence', 'paramVersion:id,version', 'reading'])->orderBy('id'),
            'records.inspections.inspector:id,name',
            'transfers' => fn ($q) => $q->with(['fromProcess:id,code', 'toProcess:id,code', 'fromStation:id,code', 'toStation:id,code', 'accepter:id,name'])->orderBy('id'),
        ]);
    }

    public function receive(Batch $batch, Request $request)
    {
        return $this->service->receive($batch, $request->user());
    }

    /** 本地模拟设备读数预览 */
    public function previewReading(Batch $batch, Request $request)
    {
        $perturb = [];
        foreach ((array) $request->query('perturb', []) as $code => $delta) {
            $perturb[$code] = (float) $delta;
        }
        return response()->json($this->service->previewReading($batch, $perturb));
    }

    public function registerProduction(Batch $batch, Request $request)
    {
        $data = $request->validate([
            'equipment_id' => 'required|integer|exists:equipment,id',
            'good_qty' => 'required|integer|min:0',
            'rework_qty' => 'required|integer|min:0',
            'scrap_qty' => 'required|integer|min:0',
            'shift' => 'nullable|string|max:20',
            'note' => 'nullable|string',
            'actual_params' => 'nullable|array',
            'actual_params.*' => 'numeric',
            'perturb' => 'nullable|array',
            'perturb.*' => 'numeric',
        ]);
        return $this->service->registerProduction($batch, $request->user(), $data);
    }

    public function inspect(ProductionRecord $productionRecord, Request $request)
    {
        $data = $request->validate([
            'passed_qty' => 'required|integer|min:0',
            'rework_qty' => 'required|integer|min:0',
            'scrap_qty' => 'required|integer|min:0',
            'rework_to_process_id' => 'nullable|integer|exists:processes,id',
            'note' => 'nullable|string',
        ]);
        return $this->service->inspect($productionRecord, $request->user(), $data);
    }

    public function split(Batch $batch, Request $request)
    {
        $data = $request->validate(['quantities' => 'required|array|min:2', 'quantities.*' => 'integer|min:1']);
        return response()->json(
            $this->service->split($batch, $request->user(), $data['quantities'])
        );
    }

    public function scrap(Batch $batch, Request $request)
    {
        $data = $request->validate([
            'quantity' => 'required|integer|min:1',
            'reason' => 'nullable|string',
        ]);
        return $this->service->scrap($batch, $request->user(), $data['quantity'], $data['reason'] ?? null);
    }

    public function resolveHold(ProductionRecord $productionRecord, Request $request)
    {
        $data = $request->validate([
            'action' => 'required|string|in:correct,shortfall_as_scrap,waive,rework,scrap',
            'good_qty' => 'nullable|integer|min:0',
            'rework_qty' => 'nullable|integer|min:0',
            'scrap_qty' => 'nullable|integer|min:0',
            'rework_to_process_id' => 'nullable|integer|exists:processes,id',
            'note' => 'nullable|string',
        ]);
        return $this->service->resolveHold($productionRecord, $request->user(), $data['action'], $data);
    }
}
