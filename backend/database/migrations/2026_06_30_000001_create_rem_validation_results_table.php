<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rem_upload_id')->constrained()->cascadeOnDelete();
            $table->string('rule_key');
            $table->string('rule_type');
            $table->enum('severity', ['error', 'warning'])->default('error');
            $table->boolean('passed');
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['rem_upload_id', 'passed']);
            $table->index(['rem_upload_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_validation_results');
    }
};
