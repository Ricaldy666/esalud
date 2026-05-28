<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rut' => $this->rut,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'health_center_id' => $this->health_center_id,
            'health_center' => $this->whenLoaded('healthCenter', fn() => [
                'id' => $this->healthCenter->id,
                'name' => $this->healthCenter->name,
                'type' => $this->healthCenter->type,
            ]),
            'roles' => $this->getRoleNames(),
            'last_login_at' => $this->last_login_at ? Carbon::parse($this->last_login_at)->toIso8601String() : null,
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($this->updated_at)->toIso8601String(),
        ];
    }
}
