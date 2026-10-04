<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\ParameterVersionController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\TraceController;
use Illuminate\Support\Facades\Route;

/*
| 认证公开接口
*/
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth.token')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);

    // 基础数据
    Route::get('/stations', [ReferenceController::class, 'stations']);
    Route::get('/processes', [ReferenceController::class, 'processes']);
    Route::get('/stations/{stationId}/equipment', [ReferenceController::class, 'stationEquipment']);

    // 工艺参数版本（工程师）
    Route::get('/parameter-versions', [ParameterVersionController::class, 'index']);
    Route::get('/parameter-versions/{parameterVersion}', [ParameterVersionController::class, 'show']);
    Route::get('/processes/{process}/templates', [ParameterVersionController::class, 'templates']);
    Route::post('/parameter-versions', [ParameterVersionController::class, 'store'])
        ->middleware('auth.token:engineer,admin');
    Route::post('/parameter-versions/{parameterVersion}/activate', [ParameterVersionController::class, 'activate'])
        ->middleware('auth.token:engineer,admin');

    // 生产单
    Route::get('/orders', [ProductionOrderController::class, 'index']);
    Route::get('/orders/{productionOrder}', [ProductionOrderController::class, 'show']);
    Route::post('/orders', [ProductionOrderController::class, 'store']);
    Route::post('/orders/{productionOrder}/release', [ProductionOrderController::class, 'release']);

    // 批次与工作流
    Route::get('/batches', [BatchController::class, 'index']);
    Route::get('/batches/{batch}', [BatchController::class, 'show']);
    Route::post('/batches/{batch}/receive', [BatchController::class, 'receive']);
    Route::get('/batches/{batch}/reading-preview', [BatchController::class, 'previewReading']);
    Route::post('/batches/{batch}/production', [BatchController::class, 'registerProduction']);
    Route::post('/records/{productionRecord}/inspect', [BatchController::class, 'inspect'])
        ->middleware('auth.token:qc');
    Route::post('/batches/{batch}/split', [BatchController::class, 'split']);
    Route::post('/batches/{batch}/scrap', [BatchController::class, 'scrap']);
    Route::post('/records/{productionRecord}/resolve-hold', [BatchController::class, 'resolveHold']);

    // 追溯与工序图
    Route::get('/orders/{productionOrder}/trace', [TraceController::class, 'orderTrace']);
    Route::get('/batches/{batch}/route', [TraceController::class, 'batchRoute']);
    Route::get('/holds', [TraceController::class, 'holds']);
});
