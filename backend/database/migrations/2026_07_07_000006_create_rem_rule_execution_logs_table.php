<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_rule_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('rem_rules')->nullOnDelete();
            $table->string('rule_key', 255);
            $table->foreignId('rem_upload_id')->nullable()->constrained('rem_uploads')->nullOnDelete();
            $table->foreignId('rem_template_structure_id')->nullable()->constrained('rem_template_structures')->nullOnDelete();
            $table->uuid('execution_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('passed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->unsignedInteger('execution_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->string('triggered_by', 20)->default('manual');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index('rem_upload_id');
            $table->index('execution_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_rule_execution_logs');
    }
};
