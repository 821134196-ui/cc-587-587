<?php

namespace App\Services;

use App\Models\ParameterVersion;

/**
 * 设备读数本地模拟器。
 *
 * 默认在参数上下限内生成读数(围绕 target 小幅波动);
 * 调用方可通过 $overrides 显式指定某指标的读数,用于演示/复现超限场景。
 */
class DeviceSimulator
{
    /**
     * @param  array<string, float>  $overrides  metric => value
     * @return array<int, array{metric:string,value:float,min:float,max:float,in_limit:bool}>
     */
    public function generate(ParameterVersion $version, array $overrides = []): array
    {
        $readings = [];

        foreach ($version->params as $metric => $limit) {
            $min = (float) $limit['min'];
            $max = (float) $limit['max'];

            if (array_key_exists($metric, $overrides)) {
                $value = (float) $overrides[$metric];
            } else {
                $target = isset($limit['target']) ? (float) $limit['target'] : ($min + $max) / 2;
                // 在上下限的 40% 区间内围绕 target 波动,保证默认不超限
                $span = ($max - $min) * 0.2;
                $value = $target + (mt_rand(-1000, 1000) / 1000) * $span;
                $value = max($min, min($max, $value));
            }

            $readings[] = [
                'metric' => $metric,
                'value' => round($value, 3),
                'min' => $min,
                'max' => $max,
                'in_limit' => $value >= $min && $value <= $max,
            ];
        }

        return $readings;
    }
}
