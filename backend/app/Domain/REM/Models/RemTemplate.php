<?php

namespace App\Domain\REM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RemTemplate extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'year', 'rem_type', 'version', 'config', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForYearAndType(Builder $query, int $year, string $type): void
    {
        $query->where('year', $year)->where('rem_type', $type);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['year', 'rem_type', 'version', 'is_active'])
            ->logOnlyDirty();
    }
}
