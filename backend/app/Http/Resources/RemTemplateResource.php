<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RemTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'year' => $this->year,
            'rem_type' => $this->rem_type,
            'version' => $this->version,
            'is_active' => $this->is_active,
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($this->updated_at)->toIso8601String(),
        ];

        if ($request->routeIs('rem-templates.show')) {
            $data['config'] = $this->config;
        }

        return $data;
    }
}
