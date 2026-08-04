<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE rem_validation_results MODIFY severity ENUM('error', 'warning', 'info') NOT NULL DEFAULT 'error'");
    }

    public function down(): void
    {
        $infoCount = DB::table('rem_validation_results')->where('severity', 'info')->count();

        if ($infoCount > 0) {
            throw new \RuntimeException(
                "No se puede revertir rem_validation_results.severity a ENUM('error','warning'): " .
                "existen {$infoCount} registro(s) con severity='info' que ese enum no puede representar. " .
                "Antes de reintentar el rollback, decida explícitamente una de estas acciones: " .
                "(a) eliminarlos, o " .
                "(b) convertirlos con UPDATE rem_validation_results SET severity='warning' WHERE severity='info'."
            );
        }

        DB::statement("ALTER TABLE rem_validation_results MODIFY severity ENUM('error', 'warning') NOT NULL DEFAULT 'error'");
    }
};
