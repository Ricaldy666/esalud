<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_calibration_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calibration_id')->constrained('rem_calibrations')->cascadeOnDelete();
            $table->string('section', 10);
            $table->string('coordinate', 15);
            $table->unsignedInteger('row')->nullable();
            $table->string('column_letter', 5)->nullable();
            $table->string('behavior', 30);
            $table->text('formula')->nullable();
            $table->string('formula_status', 20)->nullable();
            $table->text('justification')->nullable();
            $table->string('source', 20)->default('auto_detected');
            $table->json('rule_correction')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['calibration_id', 'section', 'coordinate'], 'uq_calibration_cell');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_calibration_cells');
    }
};
