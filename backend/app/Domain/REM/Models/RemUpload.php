<?php

namespace App\Domain\REM\Models;

use App\Domain\HealthCenters\Models\HealthCenter;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class RemUpload extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'uuid',
        'health_center_id',
        'user_id',
        'rem_template_id',
        'year',
        'month',
        'rem_type',
        'original_filename',
        'stored_path',
        'file_size',
        'mime_type',
        'status',
        'error_report',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'error_report' => 'array',
            'processed_at' => 'datetime',
            'year' => 'integer',
            'month' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (RemUpload $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function healthCenter()
    {
        return $this->belongsTo(HealthCenter::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function remTemplate()
    {
        return $this->belongsTo(RemTemplate::class);
    }

    public function remData()
    {
        return $this->hasMany(RemData::class);
    }

    public function validationResults()
    {
        return $this->hasMany(RemValidationResult::class);
    }

    public function scopeByCenter(Builder $query, int $id): void
    {
        $query->where('health_center_id', $id);
    }

    public function scopeByPeriod(Builder $query, int $year, int $month): void
    {
        $query->where('year', $year)->where('month', $month);
    }

    public function scopeByStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function scopeByUser(Builder $query, int $id): void
    {
        $query->where('user_id', $id);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'processed_at', 'health_center_id'])
            ->logOnlyDirty();
    }
}
