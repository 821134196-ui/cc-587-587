<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 工艺参数项定义（模板）：某产品某工序有哪些参数及其上下限
        Schema::create('parameter_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->string('code');              // TEMP / PRESSURE / SPEED
            $table->string('name');              // 温度 / 压力
            $table->string('unit')->nullable();
            $table->decimal('min_value', 12, 3); // 默认工艺上下限
            $table->decimal('max_value', 12, 3);
            $table->timestamps();
            $table->unique(['process_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_templates');
    }
};
