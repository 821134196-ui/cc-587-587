<?php

use App\Http\Controllers\Api\BatchController;
use App\Http\Controllers\Api\EquipmentController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProcessController;
use App\Http\Controllers\Api\ProcessMapController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/users', [UserController::class, 'index']);
Route::get('/processes', [ProcessController::class, 'index']);
Route::get('/process-map', [ProcessMapController::class, 'index']);

Route::get('/orders', [OrderController::class, 'index']);
Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);

Route::get('/batches', [BatchController::class, 'index']);
Route::get('/batches/{batch}', [BatchController::class, 'show']);
Route::get('/batches/{batch}/trace', [BatchController::class, 'trace']);
Route::post('/batches/{batch}/output', [BatchController::class, 'output']);
Route::post('/batches/{batch}/qc', [BatchController::class, 'qc']);
Route::post('/batches/{batch}/receive', [BatchController::class, 'receive']);
Route::post('/batches/{batch}/split', [BatchController::class, 'split']);
Route::post('/batches/{batch}/rework', [BatchController::class, 'rework']);

Route::get('/equipments/{equipment}/parameter-versions', [EquipmentController::class, 'parameterVersions']);
Route::post('/equipments/{equipment}/parameter-versions', [EquipmentController::class, 'storeParameterVersion']);
