<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Equipment;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class FactoryTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function operator(): User
    {
        return User::where('email', 'op1@factory.local')->firstOrFail();
    }

    protected function operator2(): User
    {
        return User::where('email', 'op2@factory.local')->firstOrFail();
    }

    protected function qc(): User
    {
        return User::where('email', 'qc1@factory.local')->firstOrFail();
    }

    protected function apiAs(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->withHeaders(['X-User-Id' => $user->id, 'Accept' => 'application/json'])
            ->{$method.'Json'}($uri, $data);
    }

    protected function rootBatch(): Batch
    {
        return Batch::where('origin', Batch::ORIGIN_ROOT)->firstOrFail();
    }

    protected function equipmentOf(string $processCode): Equipment
    {
        return Equipment::whereHas('process', fn ($q) => $q->where('code', $processCode))->firstOrFail();
    }

    protected function processOf(string $code): Process
    {
        return Process::where('code', $code)->firstOrFail();
    }

    /** 让批次完成当前工序:登记产出 → 质检放行(质检员) */
    protected function passStation(Batch $batch, int $qualified, int $rework, int $scrap, array $readings = []): Batch
    {
        $this->apiAs($this->operator(), 'post', "/api/batches/{$batch->id}/output", [
            'qty_qualified' => $qualified,
            'qty_rework' => $rework,
            'qty_scrap' => $scrap,
            'readings' => $readings,
        ])->assertOk();

        $this->apiAs($this->qc(), 'post', "/api/batches/{$batch->id}/qc", ['action' => 'release'])->assertOk();

        return $batch->fresh();
    }

    /** 接收到指定工序代码对应的设备 */
    protected function receiveTo(Batch $batch, string $processCode, ?User $user = null): Batch
    {
        $equipment = $this->equipmentOf($processCode);
        $this->apiAs($user ?? $this->operator2(), 'post', "/api/batches/{$batch->id}/receive", [
            'equipment_id' => $equipment->id,
        ])->assertOk();

        return $batch->fresh();
    }

    protected function newOrder(string $code, int $qty): ProductionOrder
    {
        $response = $this->apiAs($this->operator(), 'post', '/api/orders', [
            'code' => $code,
            'product_name' => '测试件',
            'planned_qty' => $qty,
            'first_batch_qty' => $qty,
        ])->assertCreated();

        return ProductionOrder::findOrFail($response->json('order.id'));
    }
}
