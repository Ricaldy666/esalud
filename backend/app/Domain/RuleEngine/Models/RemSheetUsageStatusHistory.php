<?php

namespace App\Domain\RuleEngine\Models;

use App\Domain\RemParser\Models\RemTemplateStructure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial auditable de transiciones de RemSheetUsageStatus -- una fila
 * por cada cambio de estado (aplicable->no_utilizada, no_utilizada->aplicable,
 * o cualquier transicion futura permitida).
 */
class RemSheetUsageStatusHistory extends Model
{
    protected $table = 'rem_sheet_usage_status_history';

    public $timestamps = true;

    protected $fillable = [
        'rem_sheet_usage_status_id',
        'previous_status',
        'new_status',
        'reason',
        'changed_by',
        'changed_at',
        'structure_id',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function usageStatus(): BelongsTo
    {
        return $this->belongsTo(RemSheetUsageStatus::class, 'rem_sheet_usage_status_id');
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(RemTemplateStructure::class, 'structure_id');
    }
}
