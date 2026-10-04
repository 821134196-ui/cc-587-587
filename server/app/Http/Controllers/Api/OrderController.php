<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductionOrder;
use App\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index(): JsonResponse
    {
        $orders = ProductionOrder::withCount('batches')
            ->with(['batches' => fn ($q) => $q->select('id', 'production_order_id', 'status')])
            ->orderByDesc('id')
            ->get();

        return response()->json($orders);
    }

    /**
     * 创建生产单,可同时创建首个根批次并投入首工序。
     */
    public function store(Request $request, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:64|unique:production_orders,code',
            'product_name' => 'required|string|max:128',
            'planned_qty' => 'required|integer|min:1',
            'first_batch_qty' => 'nullable|integer|min:1',
        ]);

        $order = ProductionOrder::create([
            'code' => $data['code'],
            'product_name' => $data['product_name'],
            'planned_qty' => $data['planned_qty'],
        ]);

        $batch = null;
        $qty = $data['first_batch_qty'] ?? null;
        if ($qty !== null) {
            abort_if($qty > $order->planned_qty, 422, '首批数量不能超过计划数量');
            $batch = $service->createRootBatch($order, $qty, $request->user());
        }

        return response()->json([
            'order' => $order->fresh(),
            'batch' => $batch?->load(['process', 'equipment', 'parameterVersion']),
        ], 201);
    }

    public function show(ProductionOrder $order): JsonResponse
    {
        return response()->json($order->load(['batches.process', 'batches.equipment', 'batches.parameterVersion']));
    }
}
