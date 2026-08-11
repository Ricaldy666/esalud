<?php

namespace App\Domain\RuleEngine\Models;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de uso de una hoja REM (anio+serie+sheet_name), tal como lo
 * determina Estadistica APS -- 'aplicable' (default, sin fila) o
 * 'no_utilizada'. Ver migracion para el razonamiento completo del diseño.
 */
class RemSheetUsageStatus extends Model
{
    protected $table = 'rem_sheet_usage_status';

    protected $fillable = [
        'anio',
        'serie',
        'sheet_name',
        'status',
        'reason',
        'decided_by',
        'decided_at',
        'structure_id',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function structure(): BelongsTo
    {
        return $this->belongsTo(RemTemplateStructure::class, 'structure_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(RemSheetUsageStatusHistory::class)->orderBy('changed_at');
    }
}
