<?php

namespace App\Domain\HealthCenters\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HealthCenter extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code_deis',
        'type',
        'address',
        'commune',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
