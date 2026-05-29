<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_templates', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('year');
            $table->string('rem_type', 10);
            $table->string('version', 20);
            $table->json('config');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['year', 'rem_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_templates');
    }
};
