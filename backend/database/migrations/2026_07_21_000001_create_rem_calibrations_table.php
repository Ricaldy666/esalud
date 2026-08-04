<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_calibrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('structure_id')->constrained('rem_template_structures')->cascadeOnDelete();
            $table->string('serie', 5)->default('A');
            $table->string('hoja', 10)->default('A01');
            $table->smallInteger('anio');
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 20)->default('draft');
            $table->decimal('progress_pct', 5, 2)->default(0);
            $table->unsignedInteger('total_sections')->default(12);
            $table->unsignedInteger('ready_sections')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('activated_by')->nullable()->constrained('users');
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_calibrations');
    }
};
