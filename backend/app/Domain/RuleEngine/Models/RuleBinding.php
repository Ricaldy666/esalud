<?php

namespace App\Domain\RuleEngine\Models;

use Illuminate\Database\Eloquent\Model;

class RuleBinding extends Model
{
    protected $table = 'rem_rule_bindings';

    protected $fillable = [
        'rule_id',
        'bindable_type',
        'bindable_id',
        'serie',
        'anio',
        'conditions',
        'active',
    ];

    protected $casts = [
        'conditions' => 'array',
        'active' => 'boolean',
    ];

    public function rule()
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }
}
