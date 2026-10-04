<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();           // 设备编码，如 CNC-01
            $table->string('name');
            $table->foreignId('station_id')->constrained(); // 设备所在工位
            $table->string('status')->default('idle');  // idle | running | maintenance
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
