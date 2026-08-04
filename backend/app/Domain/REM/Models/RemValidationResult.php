<?php

namespace App\Domain\REM\Models;

use App\Domain\RuleEngine\Models\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemValidationResult extends Model
{
    protected $fillable = [
        'rule_id',
        'rem_upload_id',
        'rule_key',
        'rule_type',
        'severity',
        'passed',
        'message',
        'context',
    ];

    protected $casts = [
        'passed' => 'boolean',
        'context' => 'array',
    ];

    public function remUpload(): BelongsTo
    {
        return $this->belongsTo(RemUpload::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }
}
