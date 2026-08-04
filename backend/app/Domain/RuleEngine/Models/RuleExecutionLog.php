<?php

namespace App\Domain\RuleEngine\Models;

use Illuminate\Database\Eloquent\Model;

class RuleExecutionLog extends Model
{
    protected $table = 'rem_rule_execution_logs';

    protected $fillable = [
        'rule_id',
        'rule_key',
        'rem_upload_id',
        'rem_template_structure_id',
        'execution_id',
        'status',
        'total_rows',
        'passed_rows',
        'failed_rows',
        'execution_ms',
        'error_message',
        'triggered_by',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function rule()
    {
        return $this->belongsTo(Rule::class, 'rule_id');
    }

    public function upload()
    {
        return $this->belongsTo(\App\Domain\REM\Models\RemUpload::class, 'rem_upload_id');
    }
}
