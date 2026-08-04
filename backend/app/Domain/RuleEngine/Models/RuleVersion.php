<?php

namespace App\Domain\RuleEngine\Models;

use Illuminate\Database\Eloquent\Model;

class RuleVersion extends Model
{
    protected $table = 'rem_rule_versions';

    protected $fillable = [
        'rule_id',
        'version',
        'config',
        'changelog',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
    ];

    public function rule()
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }
}
