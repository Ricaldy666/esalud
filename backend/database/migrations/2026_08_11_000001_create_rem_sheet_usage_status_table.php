<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rem_sheet_usage_status -- registra explicitamente que una hoja REM
 * (anio+serie+sheet_name) fue determinada por Estadistica APS como NO
 * utilizada, o que volvio a ser utilizada tras haber estado marcada asi.
 *
 * Diseño deliberado (aprobado por el usuario, 2026-08-11):
 * - `status` es VARCHAR, no ENUM SQL -- los valores permitidos se validan
 *   desde Laravel (RemSheetUsageStatusService::ALLOWED_STATUSES), no desde
 *   una restriccion de columna, para no acoplar estados futuros a una
 *   migracion de esquema.
 * - Asociada a (anio, serie, sheet_name), NO a un structure_id puntual --
 *   es una decision de negocio que debe sobrevivir a futuros parches
 *   estructurales de la misma campaña (ej. si A21 se vuelve a auditar
 *   estructuralmente, no debe perder su marca de "no utilizada" solo
 *   porque cambio la version de estructura activa).
 * - Solo existe una fila por hoja que se APARTA del default ('aplicable')
 *   -- la ausencia de fila significa aplicable, evita crear registros
 *   innecesarios para las hojas que si se calibran.
 * - `structure_id` es informativo/auditoria (que estructura estaba activa
 *   cuando se registro la decision), nunca la clave de busqueda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rem_sheet_usage_status', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('anio');
            $table->string('serie', 5);
            $table->string('sheet_name', 20);
            $table->string('status', 30)->default('aplicable');
            $table->text('reason')->nullable();
            $table->string('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('structure_id')->nullable()->constrained('rem_template_structures')->nullOnDelete();
            $table->timestamps();

            $table->unique(['anio', 'serie', 'sheet_name'], 'uq_rem_sheet_usage_status_anio_serie_hoja');
            $table->index('status');
        });

        Schema::create('rem_sheet_usage_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rem_sheet_usage_status_id')->constrained('rem_sheet_usage_status')->cascadeOnDelete();
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->text('reason')->nullable();
            $table->string('changed_by')->nullable();
            $table->timestamp('changed_at');
            $table->foreignId('structure_id')->nullable()->constrained('rem_template_structures')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rem_sheet_usage_status_history');
        Schema::dropIfExists('rem_sheet_usage_status');
    }
};
