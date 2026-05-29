<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rem_upload_id')->constrained()->cascadeOnDelete();
            $table->string('section', 20);
            $table->json('data');
            $table->timestamps();

            $table->index(['rem_upload_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_data');
    }
};
