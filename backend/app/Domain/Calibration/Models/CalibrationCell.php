<?php

namespace App\Domain\Calibration\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalibrationCell extends Model
{
    protected $table = 'rem_calibration_cells';

    protected $fillable = [
        'calibration_id',
        'section',
        'coordinate',
        'row',
        'column_letter',
        'behavior',
        'formula',
        'formula_status',
        'justification',
        'source',
        'rule_correction',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'row' => 'integer',
            'rule_correction' => 'array',
        ];
    }

    public function calibration(): BelongsTo
    {
        return $this->belongsTo(Calibration::class, 'calibration_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
