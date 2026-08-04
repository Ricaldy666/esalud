<?php

namespace App\Domain\Calibration\Models;

use App\Domain\RemParser\Models\RemTemplateStructure;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Calibration extends Model
{
    protected $table = 'rem_calibrations';

    protected $fillable = [
        'structure_id',
        'serie',
        'hoja',
        'anio',
        'version',
        'status',
        'progress_pct',
        'total_sections',
        'ready_sections',
        'created_by',
        'activated_by',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'version' => 'integer',
            'progress_pct' => 'decimal:2',
            'total_sections' => 'integer',
            'ready_sections' => 'integer',
            'activated_at' => 'datetime',
        ];
    }

    public function structure(): BelongsTo
    {
        return $this->belongsTo(RemTemplateStructure::class, 'structure_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function cells(): HasMany
    {
        return $this->hasMany(CalibrationCell::class, 'calibration_id');
    }
}
