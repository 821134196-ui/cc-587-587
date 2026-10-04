<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processes', function (Blueprint $table) {
            $table->id();
            $table->string('product_code');           // 产品编码（工艺路线按产品定义）
            $table->unsignedSmallInteger('sequence'); // 工序顺序 10,20,30...
            $table->string('code');                    // 工序编码 OP-10
            $table->string('name');                    // 工序名称
            $table->foreignId('station_id')->constrained(); // 默认执行工位
            $table->boolean('is_final')->default(false);    // 是否末道工序
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_code', 'sequence']);
            $table->unique(['product_code', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
