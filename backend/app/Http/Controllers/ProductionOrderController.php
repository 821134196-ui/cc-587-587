<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Services\ProductionService;
use Illuminate\Http\Request;

class ProductionOrderController extends Controller
{
    public function __construct(private readonly ProductionService $service) {}

    public function index(Request $request)
    {
        $orders = ProductionOrder::with(['creator:id,name'])
            ->withCount('batches')
            ->orderByDesc('id')
            ->get()
            ->map(function (ProductionOrder $o) {
                return array_merge($o->toArray(), ['quantity_summary' => $o->quantitySummary()]);
            });
        return $orders;
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load(['creator:id,name', 'batches.paramVersion:id,version']);
        return array_merge($productionOrder->toArray(), [
            'quantity_summary' => $productionOrder->quantitySummary(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'order_no' => 'nullable|string|max:40|unique:production_orders,order_no',
            'product_code' => 'required|string|max:40',
            'product_name' => 'required|string|max:100',
            'planned_qty' => 'required|integer|min:1',
            'note' => 'nullable|string',
        ]);
        $order = $this->service->createOrder($request->user(), $data);
        return response()->json($order, 201);
    }

    public function release(ProductionOrder $productionOrder, Request $request)
    {
        return $this->service->releaseOrder($productionOrder, $request->user());
    }
}
