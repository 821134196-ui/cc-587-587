<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // operator 操作员 | qc 质检员 | engineer 工艺工程师 | admin
            $table->string('role')->default('operator')->after('password');
            $table->string('display_name')->nullable()->after('name');
            $table->foreignId('station_id')->nullable()->after('role')
                ->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('station_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('station_id');
            $table->dropColumn(['role', 'display_name', 'is_active']);
        });
    }
};
