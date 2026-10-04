<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_readings', function (Blueprint $table) {
            // 设备读数(本地模拟),登记产出时按锁定的参数版本采集并判定
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipments')->cascadeOnDelete();
            $table->foreignId('parameter_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('metric');
            $table->double('value');
            $table->double('min_value');
            $table->double('max_value');
            $table->boolean('in_limit');
            $table->timestamps();

            $table->index(['batch_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_readings');
    }
};
