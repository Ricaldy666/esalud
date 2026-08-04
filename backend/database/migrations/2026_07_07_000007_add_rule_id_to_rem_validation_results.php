<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rem_validation_results', function (Blueprint $table) {
            $table->foreignId('rule_id')
                ->nullable()
                ->after('id')
                ->constrained('rem_rules')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rem_validation_results', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rule_id');
        });
    }
};
