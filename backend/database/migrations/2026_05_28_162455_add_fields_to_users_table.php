<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('rut', 12)->unique()->nullable()->after('name');
            $table->foreignId('health_center_id')->nullable()->constrained()->nullOnDelete()->after('rut');
            $table->boolean('is_active')->default(true)->after('health_center_id');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->softDeletes()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['health_center_id']);
            $table->dropColumn(['rut', 'health_center_id', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
