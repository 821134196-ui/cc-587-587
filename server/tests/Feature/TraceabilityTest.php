<?php

namespace Tests\Feature;

use App\Models\Batch;

/**
 * 检验四:追溯链。
 * 拆分与返工都沿原生产单保存关联,从任一节点能追到每一部分的去向,链不中断。
 */
class TraceabilityTest extends FactoryTestCase
{
    public function test_trace_shows_complete_tree_events_and_reconciliation(): void
    {
        $batch = $this->rootBatch(); // 100 @ P10

        // P10:80/15/5 → 放行 → P20(15 自动留存返工)
        $batch = $this->passStation($batch, 80, 15, 5);
        $batch = $this->receiveTo($batch, 'P20');

        // P20 拆分 80 → 50 + 30
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/split", [
            'quantities' => [50, 30],
        ])->assertCreated();
        [$s1, $s2] = Batch::where('parent_batch_id', $batch->id)->where('origin', Batch::ORIGIN_SPLIT)->orderBy('id')->get();

        // S1 走完 P20 → P30;S2 在 P20 登记 25/3/2 并返工 3 件回 P10
        $s1 = $this->passStation($s1, 48, 0, 2);
        $s1 = $this->receiveTo($s1, 'P30');

        $this->apiAs($this->operator(), 'post', "/api/batches/{$s2->id}/output", [
            'qty_qualified' => 25, 'qty_rework' => 3, 'qty_scrap' => 2,
        ])->assertOk();
        $this->apiAs($this->operator(), 'post', "/api/batches/{$s2->id}/rework", [
            'to_process_id' => $this->processOf('P10')->id,
            'equipment_id' => $this->equipmentOf('P10')->id,
            'qty' => 3,
        ])->assertCreated();

        // ---- 从最深的返工子批出发也能拿到完整追溯链 ----
        $deepest = Batch::where('origin', Batch::ORIGIN_REWORK)->orderByDesc('id')->first();
        $trace = $this->getJson("/api/batches/{$deepest->id}/trace")->assertOk();

        // 1. 树上包含全部 5 个批次:根、根-R1(自动留存)、根-S1、根-S2、S2-R1
        $tree = $trace->json('tree');
        $this->assertCount(1, $tree);
        $flatCodes = $this->flattenCodes($tree);
        sort($flatCodes);
        $this->assertSame(
            ['MO-2026-001-B1', 'MO-2026-001-B1-R1', 'MO-2026-001-B1-S1', 'MO-2026-001-B1-S2', 'MO-2026-001-B1-S2-R1'],
            $flatCodes
        );

        // 2. 每个节点都能沿 parent 链回根批次(链不中断)
        foreach (Batch::all() as $b) {
            $this->assertSame(
                $batch->id,
                $this->walkToRoot($b)->id,
                "批次 {$b->code} 的祖先链断开了"
            );
        }

        // 3. 事件链完整且有序:create/output/qc_release/receive/rework/split 等
        $events = collect($trace->json('events'));
        $types = $events->pluck('type')->unique()->sort()->values()->all();
        foreach (['create', 'output', 'qc_release', 'receive', 'rework', 'split'] as $expected) {
            $this->assertContains($expected, $types);
        }
        $timestamps = $events->pluck('created_at');
        $this->assertSame($timestamps->sort()->values()->all(), $timestamps->values()->all());

        // 4. 全链数量守恒:投入 100 = 在手 + 报废
        $recon = $trace->json('reconciliation');
        $this->assertSame(100, $recon['total_in']);
        $this->assertSame(9, $recon['scrapped']); // 5 + 2 + 2
        $this->assertSame(91, $recon['in_hand']);
        $this->assertTrue($recon['balanced']);

        // 5. 每个批次的单行核对也通过(拆分父批、返工父批都守恒)
        foreach ($recon['per_batch'] as $line) {
            $this->assertNotFalse($line['balanced'], "批次 {$line['code']} 单行核对不平");
        }
    }

    public function test_every_part_of_order_is_traceable_to_whereabouts(): void
    {
        $batch = $this->rootBatch();
        $batch = $this->passStation($batch, 70, 20, 10);
        $batch = $this->receiveTo($batch, 'P20');

        $trace = $this->getJson("/api/batches/{$batch->id}/trace")->assertOk();

        // 每一部分去向可查:70 件在 P20 在制、20 件在 P10 返工、10 件已报废
        $recon = $trace->json('reconciliation');
        $this->assertSame(70 + 20, $recon['in_hand']);
        $this->assertSame(10, $recon['scrapped']);

        $batches = collect($trace->json('batches'));
        $this->assertSame(
            ['P10', 'P20'],
            $batches->where('status', 'in_production')->map(fn ($b) => $b['process']['code'])->sort()->values()->all()
        );
    }

    private function flattenCodes(array $nodes): array
    {
        $codes = [];
        foreach ($nodes as $node) {
            $codes[] = $node['code'];
            $codes = array_merge($codes, $this->flattenCodes($node['children']));
        }

        return $codes;
    }

    private function walkToRoot(Batch $batch): Batch
    {
        $guard = 0;
        while ($batch->parent_batch_id !== null && $guard++ < 100) {
            $batch = $batch->parent;
        }

        return $batch;
    }
}
