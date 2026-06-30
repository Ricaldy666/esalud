<?php

namespace App\Domain\REM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemValidationResult extends Model
{
    protected $fillable = [
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
}
