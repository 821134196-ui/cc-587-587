<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 报废记录
        Schema::create('scrap_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 拆分记录：父批次 -> 子批次（始终挂在同一生产单上）
        Schema::create('split_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_batch_id')->constrained('batches');
            $table->foreignId('child_batch_id')->constrained('batches');
            $table->unsignedInteger('quantity');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['parent_batch_id']);
        });

        // 返工单：拒收/返工登记产生的回流批次，沿原生产单回到指定工序
        Schema::create('rework_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qc_inspection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_record_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_batch_id')->constrained('batches'); // 来源批次
            $table->foreignId('rework_batch_id')->constrained('batches'); // 返工回流新批次
            $table->foreignId('from_process_id')->constrained('processes');
            $table->foreignId('to_process_id')->constrained('processes');
            $table->unsignedInteger('quantity');
            // open 回流待加工 | in_progress 返工中 | done 返工完成(重新放行)
            $table->string('status')->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['source_batch_id']);
            $table->index(['rework_batch_id']);
        });

        // 设备读数（本地模拟）：每次登记前由设备产生读数快照
        Schema::create('equipment_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->constrained();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');            // {"TEMP": 182.5, ...}
            $table->boolean('simulated')->default(true);
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index(['equipment_id']);
        });

        // 追溯事件流：批次的每一次移动/变形都记一条，保证追溯链不中断
        Schema::create('trace_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type'); // order_released/received/production/qc/transfer/
                                          // transfer_received/split/scrap/rework_created/
                                          // rework_returned/completed/hold/hold_resolved
            $table->string('from_node')->nullable(); // "WS-A / OP-10 / CNC-01"
            $table->string('to_node')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->json('payload')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['production_order_id', 'occurred_at']);
            $table->index(['batch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trace_events');
        Schema::dropIfExists('equipment_readings');
        Schema::dropIfExists('rework_orders');
        Schema::dropIfExists('split_records');
        Schema::dropIfExists('scrap_records');
    }
};
