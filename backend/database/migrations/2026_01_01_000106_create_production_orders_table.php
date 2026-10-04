<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no')->unique();      // 生产单号 MO-20261004-001
            $table->string('product_code');
            $table->string('product_name');
            $table->unsignedInteger('planned_qty');    // 计划数量（=根批次数量）
            // planned 已排产 | released 已下发(根批次待首工位接收) | closed 已结案
            $table->string('status')->default('planned');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
