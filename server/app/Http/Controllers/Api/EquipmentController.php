<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Equipment;
use App\Services\BatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EquipmentController extends Controller
{
    public function parameterVersions(Equipment $equipment): JsonResponse
    {
        return response()->json(
            $equipment->parameterVersions()->with('creator:id,name')->orderByDesc('version')->get()
        );
    }

    /**
     * 参数换版:旧版本作废,新版本生效;已锁定旧版本的批次不受影响。
     */
    public function storeParameterVersion(Request $request, Equipment $equipment, BatchService $service): JsonResponse
    {
        $data = $request->validate([
            'params' => 'required|array|min:1',
            'params.*.min' => 'required|numeric',
            'params.*.max' => 'required|numeric',
            'params.*.target' => 'nullable|numeric',
            'params.*.unit' => 'nullable|string|max:16',
        ]);

        foreach ($data['params'] as $metric => $limit) {
            abort_if($limit['min'] > $limit['max'], 422, "指标 {$metric} 下限不能大于上限");
        }

        $version = $service->reviseParameters($equipment, $data['params'], $request->user());

        return response()->json($version, 201);
    }
}
