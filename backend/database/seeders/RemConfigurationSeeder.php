<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeder maestro: traslada la configuracion REM (estructuras detectadas,
 * catalogo de reglas, versiones y bindings) desde los fixtures JSON
 * generados localmente por `php artisan rem:export-seed-data` hacia
 * CUALQUIER entorno, incluida produccion.
 *
 * Orden obligatorio: las estructuras deben existir antes de resolver
 * bindable_id en los bindings; las reglas deben existir antes de resolver
 * rule_id en versiones y bindings.
 *
 * Uso en el servidor (despues de `git pull` y de transferir por separado
 * storage/app/private/certificacion/ -- ver plan de despliegue):
 *
 *   php artisan db:seed --class="Database\Seeders\RemConfigurationSeeder"
 *
 * Totalmente idempotente: cada seeder hijo usa updateOrCreate por clave
 * natural, así que correr este comando varias veces no duplica filas.
 */
class RemConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RemTemplateStructureSeeder::class,
            RemRulesSeeder::class,
            RemRuleVersionsSeeder::class,
            RemRuleBindingsSeeder::class,
        ]);
    }
}
