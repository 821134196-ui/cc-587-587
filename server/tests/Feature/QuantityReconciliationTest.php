<?php

namespace Tests\Feature;

use App\Models\Batch;

/**
 * 检验一:批次数量核对。
 * 数量不符 → 停在待处理,不得转给下一工位;拆分/报废/返工后的数量必须与原记录一致。
 */
class QuantityReconciliationTest extends FactoryTestCase
{
    public function test_mismatched_output_goes_on_hold_and_cannot_move_forward(): void
    {
        $batch = $this->rootBatch(); // 100 件在制

        // 90 + 5 + 3 = 98 ≠ 100 → 待处理
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 90, 'qty_rework' => 5, 'qty_scrap' => 3,
        ])->assertOk();

        $this->assertSame(Batch::STATUS_ON_HOLD, $response->json('status'));
        $this->assertStringContainsString('数量不符', $response->json('hold_reason'));

        // 待处理状态:不能质检、不能转给下一工位
        $this->apiAs($this->qc(), 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])
            ->assertStatus(409);
        $this->apiAs($this->operator2(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $this->equipmentOf('P20')->id,
        ])->assertStatus(409);

        // 批次仍停在原工序
        $this->assertSame($this->processOf('P10')->id, $batch->fresh()->current_process_id);
    }

    public function test_corrected_output_clears_hold(): void
    {
        $batch = $this->rootBatch();

        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 90, 'qty_rework' => 5, 'qty_scrap' => 3,
        ])->assertOk();

        // 修正:90 + 5 + 5 = 100 → 待质检
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => 90, 'qty_rework' => 5, 'qty_scrap' => 5,
        ])->assertOk();

        $this->assertSame(Batch::STATUS_PENDING_QC, $response->json('status'));
        $this->assertNull($response->json('hold_reason'));
    }

    public function test_split_must_conserve_quantities(): void
    {
        $batch = $this->rootBatch(); // 100 件

        // 60 + 30 = 90 ≠ 100 → 拒绝
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/split", [
            'quantities' => [60, 30],
        ])->assertStatus(422);

        // 60 + 40 = 100 → 允许,子批之和等于父批
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/split", [
            'quantities' => [60, 40],
        ])->assertCreated();

        $children = collect($response->json());
        $this->assertCount(2, $children);
        $this->assertSame(100, (int) $children->sum('qty_received'));
        $this->assertSame(Batch::STATUS_SPLIT, $batch->fresh()->status);

        foreach ($children as $child) {
            $this->assertSame($batch->production_order_id, $child['production_order_id']);
            $this->assertSame($batch->id, $child['parent_batch_id']);
        }
    }

    public function test_full_chain_reconciliation_stays_balanced(): void
    {
        $batch = $this->rootBatch(); // 100

        // P10 下料:80 合格 / 15 返工 / 5 报废 → 放行 → P20 接收(15 件返工自动留存 P10)
        $batch = $this->passStation($batch, 80, 15, 5);
        $batch = $this->receiveTo($batch, 'P20');

        $reworkChild = Batch::where('origin', Batch::ORIGIN_REWORK)->first();
        $this->assertNotNull($reworkChild);
        $this->assertSame(15, $reworkChild->qty_received);
        $this->assertSame($this->processOf('P10')->id, $reworkChild->current_process_id);

        // P20 粗加工中拆分 80 → 50 + 30
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/split", [
            'quantities' => [50, 30],
        ])->assertCreated();
        $splitChildren = Batch::where('parent_batch_id', $batch->id)->where('origin', Batch::ORIGIN_SPLIT)->get();
        $this->assertSame(80, (int) $splitChildren->sum('qty_received'));

        // 子批 50 登记产出:45 / 3 / 2
        $first = $splitChildren->firstWhere('qty_received', 50);
        $this->apiAs($this->operator(), 'post', "/api/batches/{$first->id}/output", [
            'qty_qualified' => 45, 'qty_rework' => 3, 'qty_scrap' => 2,
        ])->assertOk();

        // 全链核对:投入 100 = 在手 93 + 报废 7
        $trace = $this->getJson("/api/batches/{$batch->id}/trace")->assertOk();
        $recon = $trace->json('reconciliation');

        $this->assertSame(100, $recon['total_in']);
        $this->assertSame(7, $recon['scrapped']);
        $this->assertSame(93, $recon['in_hand']);
        $this->assertSame(100, $recon['accounted']);
        $this->assertTrue($recon['balanced']);
    }

    public function test_scrap_and_rework_quantities_match_original_records(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 80, 15, 5);

        // 手动返工 10 件回 P10,剩余返工 5 件
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/rework", [
            'to_process_id' => $this->processOf('P10')->id,
            'equipment_id' => $this->equipmentOf('P10')->id,
            'qty' => 10,
        ])->assertCreated();

        $batch = $batch->fresh();
        $this->assertSame(5, $batch->qty_rework);

        // 原登记:投入 100 = 合格 80 + 剩余返工 5 + 报废 5 + 已转出返工 10
        $reworkedOut = Batch::where('parent_batch_id', $batch->id)->where('origin', Batch::ORIGIN_REWORK)->sum('qty_initial');
        $this->assertSame(100, $batch->qty_qualified + $batch->qty_rework + $batch->qty_scrap + $reworkedOut);
        $this->assertSame(10, (int) $reworkedOut);
    }
}
