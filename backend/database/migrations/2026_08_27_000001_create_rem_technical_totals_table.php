<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_technical_totals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rem_upload_id')->constrained()->cascadeOnDelete();
            $table->string('sheet', 20);
            $table->string('rem_section_code', 20);
            $table->unsignedInteger('row_number');
            $table->string('concept')->nullable();
            $table->string('total')->nullable();
            $table->json('values');
            $table->string('exclusion_reason', 40);
            $table->timestamps();

            $table->unique(
                ['rem_upload_id', 'sheet', 'rem_section_code', 'row_number'],
                'rem_technical_totals_upload_sheet_section_row_unique'
            );
            $table->index(['rem_upload_id', 'sheet']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_technical_totals');
    }
};
