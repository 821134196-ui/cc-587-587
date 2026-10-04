<?php

namespace Database\Seeders;

use App\Models\Batch;
use App\Models\Equipment;
use App\Models\ParameterVersion;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Services\BatchService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 幂等:已初始化过则跳过,保证 start.sh 可重复执行
        if (User::count() > 0) {
            return;
        }

        // ---- 用户 ----
        $op1 = User::create(['name' => '张三(操作员)', 'email' => 'op1@factory.local', 'password' => 'password', 'role' => User::ROLE_OPERATOR]);
        $op2 = User::create(['name' => '李四(操作员)', 'email' => 'op2@factory.local', 'password' => 'password', 'role' => User::ROLE_OPERATOR]);
        User::create(['name' => '王五(质检员)', 'email' => 'qc1@factory.local', 'password' => 'password', 'role' => User::ROLE_QC]);
        User::create(['name' => '赵六(质检员)', 'email' => 'qc2@factory.local', 'password' => 'password', 'role' => User::ROLE_QC]);

        // ---- 工序 ----
        $cutting = Process::create(['code' => 'P10', 'name' => '下料', 'sequence' => 10]);
        $turning = Process::create(['code' => 'P20', 'name' => '粗加工', 'sequence' => 20]);
        $heat = Process::create(['code' => 'P30', 'name' => '热处理', 'sequence' => 30]);
        $grinding = Process::create(['code' => 'P40', 'name' => '精加工', 'sequence' => 40]);
        $packing = Process::create(['code' => 'P50', 'name' => '检验包装', 'sequence' => 50]);

        // ---- 设备与参数版本(v1) ----
        $this->equipment('EQ-SAW-01', '锯床 A', $cutting, [
            '转速' => ['min' => 800, 'max' => 1200, 'target' => 1000, 'unit' => 'rpm'],
        ]);
        $this->equipment('EQ-LATHE-01', '车床 B1', $turning, [
            '主轴转速' => ['min' => 900, 'max' => 1500, 'target' => 1200, 'unit' => 'rpm'],
            '进给量' => ['min' => 0.10, 'max' => 0.30, 'target' => 0.20, 'unit' => 'mm/r'],
        ]);
        $this->equipment('EQ-LATHE-02', '车床 B2', $turning, [
            '主轴转速' => ['min' => 900, 'max' => 1500, 'target' => 1200, 'unit' => 'rpm'],
            '进给量' => ['min' => 0.10, 'max' => 0.30, 'target' => 0.20, 'unit' => 'mm/r'],
        ]);
        $this->equipment('EQ-FURN-01', '热处理炉 C', $heat, [
            '炉温' => ['min' => 820, 'max' => 880, 'target' => 850, 'unit' => '℃'],
            '保温时间' => ['min' => 55, 'max' => 65, 'target' => 60, 'unit' => 'min'],
        ]);
        $this->equipment('EQ-GRND-01', '磨床 D', $grinding, [
            '砂轮转速' => ['min' => 1400, 'max' => 1600, 'target' => 1500, 'unit' => 'rpm'],
        ]);
        $this->equipment('EQ-PACK-01', '包装线 E', $packing, [
            '封口温度' => ['min' => 160, 'max' => 200, 'target' => 180, 'unit' => '℃'],
        ]);

        // ---- 演示生产单 + 根批次 ----
        if (ProductionOrder::count() === 0) {
            $order = ProductionOrder::create([
                'code' => 'MO-2026-001',
                'product_name' => '传动轴 φ40×300',
                'planned_qty' => 100,
            ]);
            app(BatchService::class)->createRootBatch($order, 100, $op1);
        }
    }

    private function equipment(string $code, string $name, Process $process, array $params): Equipment
    {
        $equipment = Equipment::create(['code' => $code, 'name' => $name, 'process_id' => $process->id]);
        ParameterVersion::create([
            'equipment_id' => $equipment->id,
            'version' => 1,
            'params' => $params,
            'status' => ParameterVersion::STATUS_ACTIVE,
        ]);

        return $equipment;
    }
}
