<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RuleExecutionLogResource extends JsonResource
{
    protected bool $detail = false;

    public function withDetail(): static
    {
        $this->detail = true;
        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_key' => $this->rule_key,
            'rem_upload_id' => $this->rem_upload_id,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'passed_rows' => $this->passed_rows,
            'failed_rows' => $this->failed_rows,
            'execution_ms' => $this->execution_ms,
            'error_message' => $this->error_message,
            'triggered_by' => $this->triggered_by,
            'created_at' => $this->created_at,

            'context' => $this->when($this->detail, $this->context),

            'rule' => $this->whenLoaded('rule', fn () => [
                'id' => $this->rule->id,
                'rule_key' => $this->rule->rule_key,
                'rule_type' => $this->rule->rule_type,
                'name' => $this->rule->name,
                'severity' => $this->rule->severity,
                'status' => $this->rule->status,
            ]),

            'upload' => $this->whenLoaded('upload', fn () => [
                'id' => $this->upload->id,
                'filename' => $this->upload->original_filename,
                'period' => $this->upload->year . '-' . $this->upload->month,
                'rem_type' => $this->upload->rem_type,
            ]),
        ];
    }
}
