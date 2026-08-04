<?php

namespace App\Domain\RuleEngine\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rule extends Model
{
    use SoftDeletes;

    protected $table = 'rem_rules';

    protected $fillable = [
        'rule_key',
        'rule_type',
        'source',
        'name',
        'description',
        'category',
        'severity',
        'scope',
        'config',
        'status',
        'version',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'config' => 'array',
        'metadata' => 'array',
    ];

    public function bindings(): HasMany
    {
        return $this->hasMany(RuleBinding::class, 'rule_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RuleVersion::class, 'rule_id');
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class, 'rule_id');
    }
}
