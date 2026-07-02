<?php

namespace App\Models;

use App\Domain\HealthCenters\Models\HealthCenter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'rut',
        'name',
        'email',
        'password',
        'health_center_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function healthCenter()
    {
        return $this->belongsTo(HealthCenter::class);
    }

    public function healthCenters(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(
            \App\Domain\HealthCenters\Models\HealthCenter::class,
            'user_health_centers'
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active', 'health_center_id'])
            ->logOnlyDirty();
    }
}
