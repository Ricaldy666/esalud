<?php

namespace App\Domain\REM\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Filas TOTAL/subtotal tecnicas (mecanismos #6/#8/#11/#12 de
 * RemParserService) que se calculan en memoria durante el parseo pero se
 * excluyen deliberadamente de rem_data -- ver CLAUDE.md punto 17 (deuda
 * tecnica #5) y punto 17.6 (Fase 3A). Esta tabla es de solo-auditoria: nada
 * en el motor de reglas la consume todavia (Fase 3B/3C, no implementadas).
 */
class RemTechnicalTotal extends Model
{
    protected $fillable = [
        'rem_upload_id',
        'sheet',
        'rem_section_code',
        'row_number',
        'concept',
        'total',
        'values',
        'exclusion_reason',
    ];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'row_number' => 'integer',
        ];
    }

    public function remUpload()
    {
        return $this->belongsTo(RemUpload::class);
    }
}
