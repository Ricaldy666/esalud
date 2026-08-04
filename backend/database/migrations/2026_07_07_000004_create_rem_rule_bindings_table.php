<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_rule_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('rem_rules')->cascadeOnDelete();
            $table->string('bindable_type', 50);
            $table->unsignedBigInteger('bindable_id')->nullable();
            $table->string('serie', 10)->nullable();
            $table->smallInteger('anio')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['bindable_type', 'bindable_id']);
            $table->index('serie');
            $table->index('anio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_rule_bindings');
    }
};
