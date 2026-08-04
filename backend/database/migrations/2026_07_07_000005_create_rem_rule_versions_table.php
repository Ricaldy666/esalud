<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('rem_rules')->cascadeOnDelete();
            $table->string('version', 10);
            $table->json('config');
            $table->text('changelog')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['rule_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_rule_versions');
    }
};
