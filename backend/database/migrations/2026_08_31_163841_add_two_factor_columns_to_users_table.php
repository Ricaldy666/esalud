<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Secreto TOTP (RFC 6238), cifrado en reposo via cast 'encrypted'.
            // Nulo mientras no hay enrolamiento o el enrolamiento nunca se confirmo.
            $table->text('two_factor_secret')->nullable()->after('last_login_at');

            // Codigos de recuperacion: array JSON de hashes bcrypt (Hash::make
            // por codigo, igual que password) -- nunca reversibles, un codigo
            // usado se elimina del array. Nunca se persiste el texto plano.
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');

            // 2FA se considera activo solo cuando esta columna no es null --
            // se setea unicamente tras verificar correctamente el primer
            // codigo TOTP (nunca al generar el secreto).
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');

            // Proteccion anti-replay: ultimo "timestep" TOTP aceptado (entero
            // de Google2FA::verifyKeyNewer(), no un timestamp de reloj). Un
            // codigo valido dentro de la misma ventana ya usado se rechaza.
            $table->unsignedBigInteger('two_factor_last_totp_timestamp')->nullable()->after('two_factor_confirmed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'two_factor_last_totp_timestamp',
            ]);
        });
    }
};
