<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\Equipment;
use App\Models\Process;
use App\Models\ProductionOrder;
use App\Models\Station;
use App\Models\TraceEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

class TraceService
{
    public static function node(?Station $station, ?Process $process, ?Equipment $equipment = null): ?string
    {
        if (! $station && ! $process && ! $equipment) {
            return null;
        }
        $parts = [];
        if ($station) $parts[] = $station->code;
        if ($process) $parts[] = $process->code;
        if ($equipment) $parts[] = $equipment->code;
        return implode(' / ', $parts);
    }

    public function log(
        ProductionOrder $order,
        ?Batch $batch,
        string $eventType,
        ?string $fromNode = null,
        ?string $toNode = null,
        ?int $quantity = null,
        array $payload = null,
        ?User $actor = null,
    ): TraceEvent {
        return TraceEvent::create([
            'production_order_id' => $order->id,
            'batch_id' => $batch?->id,
            'event_type' => $eventType,
            'from_node' => $fromNode,
            'to_node' => $toNode,
            'quantity' => $quantity,
            'payload' => $payload,
            'actor_id' => $actor?->id,
            'occurred_at' => Carbon::now(),
        ]);
    }
}
