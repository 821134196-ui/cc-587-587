<?php

namespace Tests\Feature;

use App\Models\Batch;

/**
 * 检验五:审批与占用规则。
 * 操作员不能批准自己生产的批次;两个工位不能同时占用同一批。
 */
class ApprovalAndConcurrencyTest extends FactoryTestCase
{
    public function test_operator_cannot_approve_own_batch(): void
    {
        $batch = $this->rootBatch();
        $operator = $this->operator();

        $this->apiAs($operator, 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 95, 'qty_rework' => 5, 'qty_scrap' => 0,
        ])->assertOk();

        // 同一操作员尝试放行 → 403
        $this->apiAs($operator, 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])
            ->assertStatus(403);
        $this->assertSame(Batch::STATUS_PENDING_QC, $batch->fresh()->status);

        // 质检员(非登记人)可以放行
        $this->apiAs($this->qc(), 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])
            ->assertOk();
        $this->assertSame(Batch::STATUS_RELEASED, $batch->fresh()->status);
    }

    public function test_second_operator_cannot_approve_batch_registered_by_themselves(): void
    {
        // 李四登记的批次,李四自己不能批,张三(非登记人)也不能越权?——规则只禁止自批
        $batch = $this->rootBatch();
        $op2 = $this->operator2();

        $this->apiAs($op2, 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 100, 'qty_rework' => 0, 'qty_scrap' => 0,
        ])->assertOk();

        $this->apiAs($op2, 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])
            ->assertStatus(403);

        // 质检员可以放行
        $this->apiAs($this->qc(), 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])
            ->assertOk();
    }

    public function test_two_stations_cannot_occupy_same_batch(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 100, 0, 0);
        $this->assertSame(Batch::STATUS_RELEASED, $batch->status);

        $equipment = $this->equipmentOf('P20');

        // 工位一接收成功
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $equipment->id,
        ])->assertOk();

        // 工位二再接收同一批 → 409,批次只被占用一次
        $this->apiAs($this->operator2(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P20')->id,
        ])->assertStatus(409);

        $this->assertSame(1, Batch::where('id', $batch->id)->where('current_process_id', $this->processOf('P20')->id)->count());
    }

    public function test_receive_requires_released_status_and_correct_equipment(): void
    {
        $batch = $this->rootBatch(); // in_production,未登记产出

        // 未放行不能接收
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P20')->id,
        ])->assertStatus(409);

        $batch = $this->passStation($batch, 100, 0, 0);

        // 设备必须属于下一工序(不能跳工序)
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P30')->id,
        ])->assertStatus(422);
    }

    public function test_write_requests_require_user_header(): void
    {
        $batch = $this->rootBatch();

        $this->postJson("/api/batches/{$batch->id}/output", [
            'qty_qualified' => 100, 'qty_rework' => 0, 'qty_scrap' => 0,
        ])->assertStatus(401);
    }

    public function test_last_station_release_completes_batch(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 100, 0, 0);
        foreach (['P20', 'P30', 'P40', 'P50'] as $station) {
            $batch = $this->receiveTo($batch, $station);
            if ($station === 'P50') {
                break;
            }
            $batch = $this->passStation($batch, 100, 0, 0);
        }

        // 末工序登记并放行 → completed
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 100, 'qty_rework' => 0, 'qty_scrap' => 0,
        ])->assertOk();
        $this->apiAs($this->qc(), 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])->assertOk();

        $this->assertSame(Batch::STATUS_COMPLETED, $batch->fresh()->status);

        // 完成后不能再接收
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P50')->id,
        ])->assertStatus(409);

        // 全链仍守恒:100 全部完成入库
        $trace = $this->getJson("/api/batches/{$batch->id}/trace")->assertOk();
        $this->assertSame(100, $trace->json('reconciliation.completed_good'));
        $this->assertTrue($trace->json('reconciliation.balanced'));
    }
}
