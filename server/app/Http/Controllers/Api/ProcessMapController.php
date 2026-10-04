<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Process;
use Illuminate\Http\JsonResponse;

class ProcessMapController extends Controller
{
    /**
     * 工序图:每道工序下的活跃批次(当前所在设备、参数版本、数量、状态)。
     */
    public function index(): JsonResponse
    {
        $processes = Process::with('equipments')->orderBy('sequence')->get();

        $batches = Batch::with(['equipment', 'parameterVersion', 'order'])
            ->whereIn('status', Batch::ACTIVE_STATUSES)
            ->orderBy('id')
            ->get()
            ->groupBy('current_process_id');

        $map = $processes->map(fn ($p) => [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'sequence' => $p->sequence,
            'equipments' => $p->equipments,
            'batches' => $batches->get($p->id, collect())->values(),
        ]);

        return response()->json($map);
    }
}
