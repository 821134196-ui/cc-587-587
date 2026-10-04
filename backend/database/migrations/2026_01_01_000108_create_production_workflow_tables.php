<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 每道工序的产出登记：合格/返工/报废数量 + 实际工艺参数（参数版本在登记时锁定）
        Schema::create('production_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('process_id')->constrained();
            $table->foreignId('station_id')->constrained();
            $table->foreignId('equipment_id')->constrained();
            $table->foreignId('operator_id')->constrained('users');
            $table->foreignId('param_version_id')->constrained('parameter_versions');
            $table->string('shift')->nullable();

            $table->unsignedInteger('input_qty');  // 进入本工序数量
            $table->unsignedInteger('good_qty');
            $table->unsignedInteger('rework_qty');
            $table->unsignedInteger('scrap_qty');

            // 实际设备读数/工艺参数值，如 {"TEMP": 182.5, "PRESSURE": 5.1}
            $table->json('actual_params');
            $table->foreignId('equipment_reading_id')->nullable()->constrained()->nullOnDelete();

            // normal | qty_mismatch 数量不符 | params_out_of_range 参数超限
            $table->string('check_status')->default('normal');
            $table->text('check_detail')->nullable(); // 超限项与差异说明
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['batch_id']);
            $table->index(['process_id']);
        });

        // 质检记录（放行/拒收）
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_record_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspector_id')->constrained('users');
            // passed 放行 | rejected 拒收返工 | scrapped 判废
            $table->string('decision');
            $table->foreignId('rework_to_process_id')->nullable()->constrained('processes');
            $table->unsignedInteger('passed_qty')->default(0);
            $table->unsignedInteger('rework_qty')->default(0);
            $table->unsignedInteger('scrap_qty')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['batch_id']);
        });

        // 转序单：质检放行后生成，目标工位接收前批次不可被其他工位占用
        Schema::create('batch_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_process_id')->nullable()->constrained('processes');
            $table->foreignId('from_station_id')->nullable()->constrained('stations');
            $table->foreignId('to_process_id')->constrained('processes');
            $table->foreignId('to_station_id')->constrained('stations');
            $table->foreignId('param_version_id')->constrained('parameter_versions'); // 流转携带锁定版本
            $table->unsignedInteger('quantity');
            // pending 待接收（批次锁定在途，其他工位不可占用）| accepted 已接收 | cancelled 已取消
            $table->string('status')->default('pending');
            $table->foreignId('released_by')->constrained('users');
            $table->foreignId('accepted_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'status']);
            $table->index(['to_station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_transfers');
        Schema::dropIfExists('qc_inspections');
        Schema::dropIfExists('production_records');
    }
};
