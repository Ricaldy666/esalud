<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_rules', function (Blueprint $table) {
            $table->id();
            $table->string('rule_key', 255)->unique();
            $table->string('rule_type', 50);
            $table->string('source', 30)->default('excel_formula');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('severity', 20)->default('error');
            $table->string('scope', 30)->default('per_row');
            $table->json('config');
            $table->string('status', 20)->default('active');
            $table->string('version', 10)->default('1.0.0');
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('source');
            $table->index('rule_type');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_rules');
    }
};
