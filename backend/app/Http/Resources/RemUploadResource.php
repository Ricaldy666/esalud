<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RemUploadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'year' => $this->year,
            'month' => $this->month,
            'rem_type' => $this->rem_type,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'status' => $this->status,
            'error_report' => $this->error_report,
            'health_center' => $this->whenLoaded('healthCenter', fn() => [
                'id' => $this->healthCenter->id,
                'name' => $this->healthCenter->name,
                'type' => $this->healthCenter->type,
            ]),
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'rem_template' => $this->whenLoaded('remTemplate', fn() => [
                'id' => $this->remTemplate->id,
                'version' => $this->remTemplate->version,
            ]),
            'processed_at' => $this->processed_at
                ? Carbon::parse($this->processed_at)->toIso8601String()
                : null,
            'created_at' => Carbon::parse($this->created_at)->toIso8601String(),
            'updated_at' => Carbon::parse($this->updated_at)->toIso8601String(),
        ];
    }
}
