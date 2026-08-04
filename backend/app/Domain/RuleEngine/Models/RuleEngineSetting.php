<?php

namespace App\Domain\RuleEngine\Models;

use Illuminate\Database\Eloquent\Model;

class RuleEngineSetting extends Model
{
    protected $table = 'rule_engine_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'string',
    ];
}
