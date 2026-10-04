<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Equipment;
use App\Models\Process;
use App\Services\BatchService;
use App\Services\TraceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $batches = Batch::with(['process', 'equipment', 'parameterVersion', 'order'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('process_id'), fn ($q, $p) => $q->where('current_process_id', $p))
            ->orderByDesc('id')
            ->get();

        return response()->json($batches);
    }

    public function show(Batch $batch): JsonResponse
    {
        $batch->load([
            'process', 'equipment', 'parameterVersion', 'order', 'parent',
            'registrar', 'qcInspector',
            'children' => fn ($q) => $q->with(['process', 'equipment'])->orderBy('id'),
        ]);
        $batch->setRelation(
            'readings',
            $batch->readings()->latest('id')->limit(20)->get()
        );

        return response()->json($batch);
    }

    /**
     * 追溯链:批次树 + 全部事件 + 全链数量核对。
     */
    public function trace(Batch $batch, TraceService $trace): JsonResponse
    {
        return response()->json($trace->trace($batch));
    }

    /**
     * 操作员登记产出(触发设备读数采集与数量/参数校验)。
     */
    public function output(Request $request, Batch $batch, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'qty_qualified' => 'required|integer|min:0',
            'qty_rework' => 'required|integer|min:0',
            'qty_scrap' => 'required|integer|min:0',
            'readings' => 'nullable|array',
            'readings.*' => 'numeric',
        ]);

        $result = $service->registerOutput($batch, $data, $request->user());

        return response()->json($result->fresh()->load(['process', 'equipment', 'parameterVersion']));
    }

    /**
     * 质检:action = release | reject。
     */
    public function qc(Request $request, Batch $batch, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'action' => 'required|in:release,reject',
            'note' => 'nullable|string|max:255',
        ]);

        $result = $data['action'] === 'release'
            ? $service->qcRelease($batch, $request->user(), $data['note'] ?? null)
            : $service->qcReject($batch, $request->user(), $data['note'] ?? null);

        return response()->json($result->fresh()->load(['process', 'equipment', 'parameterVersion']));
    }

    /**
     * 下一工位接收(指定接收设备)。
     */
    public function receive(Request $request, Batch $batch, BatchService $service): JsonResponse
    {
        $data = $request->validate(['equipment_id' => 'required|exists:equipments,id']);

        $result = $service->receive($batch, Equipment::findOrFail($data['equipment_id']), $request->user());

        return response()->json($result->load(['process', 'equipment', 'parameterVersion']));
    }

    /**
     * 拆分批次。
     */
    public function split(Request $request, Batch $batch, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'quantities' => 'required|array|min:2',
            'quantities.*' => 'required|integer|min:1',
        ]);

        $children = $service->split($batch, $data['quantities'], $request->user());

        return response()->json(Batch::with(['process', 'equipment', 'parameterVersion'])->find(collect($children)->pluck('id')), 201);
    }

    /**
     * 返工回流到前工序。
     */
    public function rework(Request $request, Batch $batch, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'to_process_id' => 'required|exists:processes,id',
            'equipment_id' => 'required|exists:equipments,id',
            'qty' => 'required|integer|min:1',
        ]);

        $child = $service->rework(
            $batch,
            Process::findOrFail($data['to_process_id']),
            Equipment::findOrFail($data['equipment_id']),
            (int) $data['qty'],
            $request->user()
        );

        return response()->json($child->load(['process', 'equipment', 'parameterVersion']), 201);
    }
}
