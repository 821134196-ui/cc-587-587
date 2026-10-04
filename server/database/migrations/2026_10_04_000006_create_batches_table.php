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
            $table->string('code')->unique();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            // 拆分/返工产生的子批次指向父批次,沿原生产单保存关联
            $table->foreignId('parent_batch_id')->nullable()->constrained('batches')->nullOnDelete();
            $table->string('origin')->default('root'); // root | split | rework
            $table->foreignId('current_process_id')->constrained('processes');
            $table->foreignId('current_equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            // 进入本工序时锁定的参数版本,换版不影响在制批次
            $table->foreignId('parameter_version_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('qty_initial');           // 批次产生时的数量
            $table->unsignedInteger('qty_received');          // 本工序投入(在手)数量
            $table->unsignedInteger('qty_qualified')->default(0); // 本工序登记:合格
            $table->unsignedInteger('qty_rework')->default(0);    // 本工序登记:待返工(剩余)
            $table->unsignedInteger('qty_scrap')->default(0);     // 本工序登记:报废
            $table->string('status')->default('in_production');
            // in_production | pending_qc | released | on_hold | split | completed
            $table->string('hold_reason')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('qc_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('qc_at')->nullable();
            $table->timestamps();

            $table->index(['production_order_id', 'status']);
            $table->index(['current_process_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batches');
    }
};
