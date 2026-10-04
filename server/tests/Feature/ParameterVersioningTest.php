<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\ParameterVersion;

/**
 * 检验二:参数换版与超限。
 * 批次接收时锁定参数版本;换版后原批次保留实际使用的版本;读数超限 → 待处理。
 */
class ParameterVersioningTest extends FactoryTestCase
{
    public function test_batch_locks_parameter_version_and_keeps_it_after_revision(): void
    {
        $batch = $this->rootBatch();
        $lathe = $this->equipmentOf('P20');

        // 批次进入粗加工,锁定车床 v1
        $batch = $this->passStation($batch, 95, 0, 5);
        $batch = $this->receiveTo($batch, 'P20');
        $lockedVersionId = $batch->parameter_version_id;
        $this->assertSame(1, $batch->parameterVersion->version);

        // 参数换版:车床启用 v2
        $this->apiAs($this->qc(), 'post', "/api/equipments/{$lathe->id}/parameter-versions", [
            'params' => [
                '主轴转速' => ['min' => 1000, 'max' => 1600, 'target' => 1300, 'unit' => 'rpm'],
                '进给量' => ['min' => 0.10, 'max' => 0.35, 'target' => 0.22, 'unit' => 'mm/r'],
            ],
        ])->assertCreated();

        $this->assertSame(2, $lathe->fresh()->activeParameterVersion->version);
        $this->assertSame(
            ParameterVersion::STATUS_SUPERSEDED,
            ParameterVersion::where('equipment_id', $lathe->id)->where('version', 1)->first()->status
        );

        // 在制批次仍锁定 v1(实际使用的版本)
        $this->assertSame($lockedVersionId, $batch->fresh()->parameter_version_id);
        $this->assertSame(1, $batch->fresh()->parameterVersion->version);

        // 新批次进入粗加工则锁定 v2
        $order2 = $this->newOrder('MO-2026-002', 50);
        $batch2 = $order2->batches()->first();
        $batch2 = $this->passStation($batch2, 50, 0, 0);
        $batch2 = $this->receiveTo($batch2, 'P20');
        $this->assertSame(2, $batch2->parameterVersion->version);
    }

    public function test_out_of_limit_reading_goes_on_hold(): void
    {
        $batch = $this->rootBatch(); // 锯床:转速限 800~1200

        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 98, 'qty_rework' => 2, 'qty_scrap' => 0,
            'readings' => ['转速' => 1350], // 超限
        ])->assertOk();

        $this->assertSame(Batch::STATUS_ON_HOLD, $response->json('status'));
        $this->assertStringContainsString('参数超限', $response->json('hold_reason'));

        // 读数已留痕,判定为超限
        $reading = $batch->fresh()->readings()->where('metric', '转速')->latest('id')->first();
        $this->assertNotNull($reading);
        $this->assertFalse($reading->in_limit);

        // 不得转给下一工位
        $this->apiAs($this->operator2(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P20')->id,
        ])->assertStatus(409);
    }

    public function test_readings_are_judged_by_locked_version_not_latest(): void
    {
        $batch = $this->rootBatch();
        $saw = $this->equipmentOf('P10');

        // 批次锁定锯床 v1(转速上限 1200)后,参数换版 v2(上限放宽到 1400)
        $lockedVersionId = $batch->parameter_version_id;
        $this->apiAs($this->qc(), 'post', "/api/equipments/{$saw->id}/parameter-versions", [
            'params' => ['转速' => ['min' => 800, 'max' => 1400, 'target' => 1100, 'unit' => 'rpm']],
        ])->assertCreated();

        // 读数 1300:按 v2 合格,但该批锁定 v1 → 仍判超限
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 100, 'qty_rework' => 0, 'qty_scrap' => 0,
            'readings' => ['转速' => 1300],
        ])->assertOk();

        $this->assertSame(Batch::STATUS_ON_HOLD, $response->json('status'));
        $this->assertSame($lockedVersionId, $batch->fresh()->parameter_version_id);

        // 新批次锁定 v2,同样读数 1300 → 合格
        $order2 = $this->newOrder('MO-2026-003', 20);
        $batch2 = $order2->batches()->first();
        $this->assertSame(2, $batch2->parameterVersion->version);

        $response2 = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch2->id}/output", [
            'qty_qualified' => 20, 'qty_rework' => 0, 'qty_scrap' => 0,
            'readings' => ['转速' => 1300],
        ])->assertOk();

        $this->assertSame(Batch::STATUS_PENDING_QC, $response2->json('status'));
    }
}
