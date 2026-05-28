<?php

namespace App\Http\Resources;

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
            'roles' => $this->getRoleNames(),
            'last_login_at' => $this->last_login_at,
        ];
    }
}
