<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RuleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule_key' => $this->rule_key,
            'rule_type' => $this->rule_type,
            'name' => $this->name,
            'source' => $this->source,
            'category' => $this->category,
            'severity' => $this->severity,
            'status' => $this->status,
            'version' => $this->version,
            'updated_at' => $this->updated_at,

            'description' => $this->when($this->relationLoaded('bindings'), $this->description),
            'scope' => $this->when($this->relationLoaded('bindings'), $this->scope),
            'config' => $this->when($this->relationLoaded('bindings'), $this->config),
            'metadata' => $this->when($this->relationLoaded('bindings'), $this->metadata),
            'created_at' => $this->when($this->relationLoaded('bindings'), $this->created_at),

            'bindings' => RuleBindingResource::collection($this->whenLoaded('bindings')),
            'versions' => RuleVersionResource::collection($this->whenLoaded('versions')),
            'execution_logs' => RuleExecutionLogResource::collection($this->whenLoaded('executionLogs')),
        ];
    }
}
