<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Equipment;
use App\Models\ParameterTemplate;
use App\Models\ParameterVersion;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\Station;
use App\Models\User;
use App\Services\ProductionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------- 工位 ----------------
        $stations = [];
        foreach ([
            ['WS-CUT', '下料工位'],
            ['WS-CNC', '机加工位'],
            ['WS-WLD', '焊接工位'],
            ['WS-QC', '质检中心'],
        ] as [$code, $name]) {
            $stations[$code] = Station::create(['code' => $code, 'name' => $name]);
        }

        // ---------------- 设备 ----------------
        $equipment = [];
        foreach ([
            ['CUT-01', '数控切割机', 'WS-CUT'],
            ['CNC-01', '立式加工中心', 'WS-CNC'],
            ['CNC-02', '卧式加工中心', 'WS-CNC'],
            ['WLD-01', '机器人焊机', 'WS-WLD'],
            ['QCI-01', '三坐标测量仪', 'WS-QC'],
        ] as [$code, $name, $stationCode]) {
            $equipment[$code] = Equipment::create([
                'code' => $code, 'name' => $name, 'station_id' => $stations[$stationCode]->id,
            ]);
        }

        // ---------------- 用户 ----------------
        $mk = function (string $name, string $email, string $role, ?string $stationCode = null) use ($stations) {
            return User::create([
                'name' => $name,
                'display_name' => $name,
                'email' => $email,
                'password' => Hash::make('password'),
                'role' => $role,
                'station_id' => $stationCode ? $stations[$stationCode]->id : null,
                'is_active' => true,
            ]);
        };
        $mk('下料员·张工', 'cut@factory.local', 'operator', 'WS-CUT');
        $mk('机加员·李工', 'cnc@factory.local', 'operator', 'WS-CNC');
        $mk('焊接员·王工', 'weld@factory.local', 'operator', 'WS-WLD');
        $mk('质检员·赵检', 'qc@factory.local', 'qc');
        $mk('工艺工程师·钱工', 'eng@factory.local', 'engineer');
        $mk('系统管理员', 'admin@factory.local', 'admin');

        // ---------------- 产品 P-AX100 工艺路线 + 参数 ----------------
        $productCode = 'P-AX100';
        $productName = 'AX100 传动轴组件';

        $routeDef = [
            [10, 'OP-10', '下料', 'WS-CUT', false, [
                ['CUT_SPEED', '切割速度', 'mm/s', 40, 60, 50],
                ['CUT_TEMP', '切割温度', '℃', 20, 80, 45],
            ]],
            [20, 'OP-20', '机加工', 'WS-CNC', false, [
                ['SPINDLE_SPEED', '主轴转速', 'rpm', 1800, 2400, 2100],
                ['FEED_RATE', '进给速率', 'mm/min', 120, 200, 160],
                ['COOLANT_TEMP', '冷却液温度', '℃', 18, 26, 22],
            ]],
            [30, 'OP-30', '焊接', 'WS-WLD', true, [
                ['WELD_CURRENT', '焊接电流', 'A', 180, 230, 205],
                ['WELD_VOLTAGE', '焊接电压', 'V', 24, 30, 27],
                ['GAS_FLOW', '保护气流量', 'L/min', 12, 18, 15],
            ]],
        ];

        foreach ($routeDef as [$seq, $code, $name, $stationCode, $isFinal, $params]) {
            $process = Process::create([
                'product_code' => $productCode,
                'sequence' => $seq,
                'code' => $code,
                'name' => $name,
                'station_id' => $stations[$stationCode]->id,
                'is_final' => $isFinal,
            ]);

            $templates = [];
            foreach ($params as [$pcode, $pname, $unit, $min, $max, $set]) {
                $templates[$pcode] = ParameterTemplate::create([
                    'process_id' => $process->id,
                    'code' => $pcode,
                    'name' => $pname,
                    'unit' => $unit,
                    'min_value' => $min,
                    'max_value' => $max,
                ]);
            }

            // v1 初始启用版本
            $v1 = ParameterVersion::create([
                'process_id' => $process->id,
                'version' => 'v1',
                'status' => 'active',
                'change_note' => '初始工艺参数版本',
                'created_by' => null,
                'activated_at' => now(),
            ]);
            foreach ($params as [$pcode, , , $min, $max, $set]) {
                $v1->items()->create([
                    'parameter_template_id' => $templates[$pcode]->id,
                    'set_value' => $set,
                    'min_value' => $min,
                    'max_value' => $max,
                ]);
            }
        }

        // ---------------- 演示生产单（不自动开工，留给界面/验证脚本） ----------------
        $service = app(ProductionService::class);
        $admin = User::where('email', 'admin@factory.local')->first();
        $service->createOrder($admin, [
            'order_no' => 'MO-20261004-001',
            'product_code' => $productCode,
            'product_name' => $productName,
            'planned_qty' => 100,
            'note' => '演示生产单（100 件）',
        ]);
        $order = ProductionOrder::where('order_no', 'MO-20261004-001')->firstOrFail();
        $service->releaseOrder($order, $admin);
    }
}
