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
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('sequence'); // 工序顺序,小的在前
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processes');
    }
};
