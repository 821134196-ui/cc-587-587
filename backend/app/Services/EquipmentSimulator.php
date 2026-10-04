<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Batch;
use App\Models\Equipment;
use App\Models\EquipmentReading;
use App\Models\ParameterVersion;
use Illuminate\Support\Carbon;

/**
 * 设备读数本地模拟器：
 * 真实工厂由 PLC/SCADA 上报，这里按当前工艺参数版本的设定值生成模拟读数。
 * perturb: 对指定参数施加扰动（演示/测试参数超限场景），如 ["TEMP" => +15]
 */
class EquipmentSimulator
{
    public function read(Equipment $equipment, ParameterVersion $version, ?Batch $batch = null, array $perturb = []): EquipmentReading
    {
        $items = $version->items()->with('template')->get();
        if ($items->isEmpty()) {
            throw new BusinessException("参数版本 {$version->version} 未配置任何参数项，无法采集设备读数");
        }

        $payload = [];
        foreach ($items as $item) {
            $code = $item->template->code;
            $set = (float) $item->set_value;
            $span = max((float) $item->max_value - (float) $item->min_value, 1.0);
            // 设定值附近 ±2% 量程的随机漂移（确定性可复现由调用方通过 perturb 控制）
            $drift = $span * (mt_rand(-20, 20) / 1000); // ±2%
            $value = round($set + $drift + (float) ($perturb[$code] ?? 0), 3);
            $payload[$code] = $value;
        }

        return EquipmentReading::create([
            'equipment_id' => $equipment->id,
            'batch_id' => $batch?->id,
            'payload' => $payload,
            'simulated' => true,
            'captured_at' => Carbon::now(),
        ]);
    }

    /**
     * 用固定读数登记（前端可直接输入/设备回传），不经过随机模拟
     */
    public function snapshot(Equipment $equipment, array $payload, ?Batch $batch = null): EquipmentReading
    {
        return EquipmentReading::create([
            'equipment_id' => $equipment->id,
            'batch_id' => $batch?->id,
            'payload' => $payload,
            'simulated' => true,
            'captured_at' => Carbon::now(),
        ]);
    }
}
