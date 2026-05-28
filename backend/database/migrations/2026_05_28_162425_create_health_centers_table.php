<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('code_deis', 20)->unique();
            $table->string('type'); // CESFAM, CECOSF, PSR, SAPU, SAR, OTRO
            $table->string('address', 255)->nullable();
            $table->string('commune', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_centers');
    }
};
