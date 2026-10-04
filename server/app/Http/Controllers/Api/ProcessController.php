<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Process;
use Illuminate\Http\JsonResponse;

class ProcessController extends Controller
{
    public function index(): JsonResponse
    {
        $processes = Process::with(['equipments.activeParameterVersion'])
            ->orderBy('sequence')
            ->get();

        return response()->json($processes);
    }
}
