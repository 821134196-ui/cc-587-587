<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 工艺参数版本：版本一旦启用即不可变，换版通过新建版本实现
        Schema::create('parameter_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('process_id')->constrained()->cascadeOnDelete();
            $table->string('version');             // v1 / v2 ...
            $table->string('status')->default('draft'); // draft | active | retired
            $table->text('change_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['process_id', 'version']);
        });

        Schema::create('parameter_version_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parameter_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parameter_template_id')->constrained()->cascadeOnDelete();
            $table->decimal('set_value', 12, 3);   // 该版本设定值
            $table->decimal('min_value', 12, 3);   // 该版本生效上下限（版本固化）
            $table->decimal('max_value', 12, 3);
            $table->timestamps();
            $table->unique(['parameter_version_id', 'parameter_template_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_version_items');
        Schema::dropIfExists('parameter_versions');
    }
};
