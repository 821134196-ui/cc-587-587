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
            $table->string('code')->unique();
            $table->string('product_name');
            $table->unsignedInteger('planned_qty');
            $table->string('status')->default('open'); // open | closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_orders');
    }
};
