<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('batch_events', function (Blueprint $table) {
            // 追溯链:每一次转序/登记/质检/拆分/返工都留痕
            $table->id();
            $table->foreignId('batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            // create | output | hold | qc_release | qc_reject | receive | split | rework | complete
            $table->foreignId('from_process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('to_process_id')->nullable()->constrained('processes')->nullOnDelete();
            $table->foreignId('from_equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('to_equipment_id')->nullable()->constrained('equipments')->nullOnDelete();
            $table->foreignId('parameter_version_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('qty')->nullable();
            $table->integer('qty_qualified')->nullable();
            $table->integer('qty_rework')->nullable();
            $table->integer('qty_scrap')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->json('meta')->nullable(); // 如 split/rework 的子批次 id 列表
            $table->timestamps();

            $table->index(['batch_id', 'created_at']);
            $table->index(['production_order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_events');
    }
};
