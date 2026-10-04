<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_no')->unique();          // 批次号（含层级后缀）
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('root_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->foreignId('parent_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            // origin: root 根批次 | split 拆分产生 | rework 返工回流产生
            $table->string('origin')->default('root');
            $table->unsignedSmallInteger('depth')->default(0); // 拆分/返工层级
            $table->string('product_code');

            // 当前位置（工序图节点）
            $table->foreignId('process_id')->nullable()->constrained();
            $table->foreignId('station_id')->nullable()->constrained();
            $table->foreignId('equipment_id')->nullable()->constrained();
            // 当前工序锁定（实际使用）的工艺参数版本；历史版本见 production_records
            $table->foreignId('param_version_id')->nullable()->constrained('parameter_versions');

            $table->unsignedInteger('qty');               // 当前持有数量
            // queued 待接收 | in_progress 加工中 | qc_pending 待质检 | on_hold 待处理
            // released 已放行待接收 | completed 完工 | split 已拆分 | scrapped 已报废
            $table->string('status')->default('queued');
            $table->string('on_hold_reason')->nullable(); // qty_mismatch | params_out_of_range | qc_rejected
            $table->unsignedBigInteger('current_record_id')->nullable(); // 循环外键：当前工序登记记录
            $table->index('current_record_id');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['production_order_id']);
            $table->index(['parent_batch_id']);
            $table->index(['root_batch_id']);
            $table->index(['station_id', 'status']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
