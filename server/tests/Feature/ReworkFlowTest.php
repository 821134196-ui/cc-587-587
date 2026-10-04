<?php

namespace Tests\Feature;

use App\Models\Batch;

/**
 * 检验三:返工回流。
 * 返工子批沿原生产单保存关联,回到前工序后可继续正常流转,数量始终守恒。
 */
class ReworkFlowTest extends FactoryTestCase
{
    public function test_rework_creates_linked_child_on_same_order(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 85, 10, 5); // P10 产出:10 件待返工
        $batch = $this->receiveTo($batch, 'P20');
        $batch = $this->passStation($batch, 80, 5, 0);  // P20 又检出 5 件待返工

        // 从 P20 回流 5 件到 P10 返工
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/rework", [
            'to_process_id' => $this->processOf('P10')->id,
            'equipment_id' => $this->equipmentOf('P10')->id,
            'qty' => 5,
        ])->assertCreated();

        $child = Batch::findOrFail($response->json('id'));
        $this->assertSame(Batch::ORIGIN_REWORK, $child->origin);
        $this->assertSame($batch->id, $child->parent_batch_id);
        $this->assertSame($batch->production_order_id, $child->production_order_id); // 沿原生产单
        $this->assertSame($this->processOf('P10')->id, $child->current_process_id);
        $this->assertSame(5, $child->qty_received);
        $this->assertSame(0, $batch->fresh()->qty_rework); // 父批返工数量已扣减
    }

    public function test_rework_child_flows_forward_and_chain_stays_balanced(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 90, 10, 0);

        // 10 件回流 P10 返工 → 返工批重新走 P10 → P20
        $response = $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/rework", [
            'to_process_id' => $this->processOf('P10')->id,
            'equipment_id' => $this->equipmentOf('P10')->id,
            'qty' => 10,
        ])->assertCreated();
        $reworkChild = Batch::findOrFail($response->json('id'));

        $reworkChild = $this->passStation($reworkChild, 9, 0, 1); // 返工后 9 合格 1 报废
        $reworkChild = $this->receiveTo($reworkChild, 'P20');
        $this->assertSame($this->processOf('P20')->id, $reworkChild->current_process_id);

        // 全链核对:投入 100 = 父批在手 90 + 返工批在手 9 + 报废 1
        $trace = $this->getJson("/api/batches/{$batch->id}/trace")->assertOk();
        $recon = $trace->json('reconciliation');
        $this->assertSame(100, $recon['total_in']);
        $this->assertSame(99, $recon['in_hand']);
        $this->assertSame(1, $recon['scrapped']);
        $this->assertTrue($recon['balanced']);
    }

    public function test_rework_cannot_go_forward_or_exceed_registered_qty(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 90, 10, 0);

        // 不能"回流"到更靠后的工序
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/rework", [
            'to_process_id' => $this->processOf('P30')->id,
            'equipment_id' => $this->equipmentOf('P30')->id,
            'qty' => 5,
        ])->assertStatus(422);

        // 不能超过登记的返工数量
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/rework", [
            'to_process_id' => $this->processOf('P10')->id,
            'equipment_id' => $this->equipmentOf('P10')->id,
            'qty' => 11,
        ])->assertStatus(422);
    }

    public function test_leftover_rework_is_kept_at_current_station_on_receive(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 80, 15, 5);

        // 合格品转 P20,15 件返工自动留在 P10 生成返工子批
        $batch = $this->receiveTo($batch, 'P20');

        $auto = Batch::where('origin', Batch::ORIGIN_REWORK)->where('parent_batch_id', $batch->id)->first();
        $this->assertNotNull($auto);
        $this->assertSame(15, $auto->qty_received);
        $this->assertSame($this->processOf('P10')->id, $auto->current_process_id);
        $this->assertSame($batch->production_order_id, $auto->production_order_id);

        // 事件留痕:rework 事件标记为自动
        $event = $auto->parent->events()->where('type', 'rework')->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertTrue($event->meta['auto']);
    }
}
