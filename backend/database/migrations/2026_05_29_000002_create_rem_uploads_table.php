<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_uploads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('health_center_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('rem_template_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('year');
            $table->tinyInteger('month');
            $table->string('rem_type', 10);
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->unsignedInteger('file_size');
            $table->string('mime_type', 100);
            $table->string('status', 20);
            $table->json('error_report')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['health_center_id', 'year', 'month', 'rem_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_uploads');
    }
};
