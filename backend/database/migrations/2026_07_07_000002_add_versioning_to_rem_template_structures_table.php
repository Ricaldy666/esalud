<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rem_template_structures', function (Blueprint $table) {
            $table->unsignedTinyInteger('version_number')->default(1)->after('hash_estructura');
            $table->timestamp('approved_at')->nullable()->after('version_number');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->after('approved_at');
            $table->foreignId('superseded_by_id')->nullable()->constrained('rem_template_structures')->nullOnDelete()->after('approved_by');
            $table->text('notes')->nullable()->after('superseded_by_id');
        });

        DB::table('rem_template_structures')->whereNull('version_number')->update(['version_number' => 1]);

        Schema::table('rem_template_structures', function (Blueprint $table) {
            $table->dropUnique(['anio', 'serie', 'hash_estructura']);

            $table->unique(['anio', 'serie', 'version_number']);
            $table->index(['anio', 'serie', 'hash_estructura']);
        });

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE rem_template_structures MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE rem_template_structures MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active'");
        }

        Schema::table('rem_template_structures', function (Blueprint $table) {
            $table->dropUnique(['anio', 'serie', 'version_number']);
            $table->dropIndex(['anio', 'serie', 'hash_estructura']);

            $table->unique(['anio', 'serie', 'hash_estructura']);
        });

        Schema::table('rem_template_structures', function (Blueprint $table) {
            $table->dropColumn(['version_number', 'approved_at', 'approved_by', 'superseded_by_id', 'notes']);
        });
    }
};
