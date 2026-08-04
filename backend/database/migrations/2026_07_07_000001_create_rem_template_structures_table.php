<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_template_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rem_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rem_template_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('anio');
            $table->string('serie', 10);
            $table->string('hash_estructura', 64);
            $table->json('estructura');
            $table->json('metadata')->nullable();
            $table->string('source_filename', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['anio', 'serie', 'hash_estructura']);
            $table->index('rem_upload_id');
            $table->index('rem_template_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_template_structures');
    }
};
