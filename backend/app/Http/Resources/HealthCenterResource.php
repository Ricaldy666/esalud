<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HealthCenterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code_deis' => $this->code_deis,
            'type' => $this->type,
            'address' => $this->address,
            'commune' => $this->commune,
            'is_active' => $this->is_active,
            'users_count' => $this->whenCounted('users'),
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($this->updated_at)->toIso8601String(),
        ];
    }
}
