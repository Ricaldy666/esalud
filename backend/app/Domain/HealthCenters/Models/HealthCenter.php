<?php

namespace App\Domain\HealthCenters\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HealthCenter extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'code_deis', 'type', 'address', 'commune', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'code_deis', 'type', 'address', 'commune', 'is_active'])
            ->logOnlyDirty();
    }
}
