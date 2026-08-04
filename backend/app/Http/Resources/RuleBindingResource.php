<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RuleBindingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bindable_type' => $this->bindable_type,
            'bindable_id' => $this->bindable_id,
            'serie' => $this->serie,
            'anio' => $this->anio,
            'conditions' => $this->conditions,
            'active' => $this->active,
            'created_at' => $this->created_at,
        ];
    }
}
