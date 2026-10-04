<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_versions', function (Blueprint $table) {
            // 某台设备某一版工艺参数;批次接收时锁定当时 active 的版本
            $table->id();
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->unsignedInteger('version');
            // params: { "<metric>": {"min":..,"max":..,"target":..,"unit":".."}, ... }
            $table->json('params');
            $table->string('status')->default('active'); // active | superseded
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['equipment_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_versions');
    }
};
