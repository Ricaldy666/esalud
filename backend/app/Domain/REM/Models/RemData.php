<?php

namespace App\Domain\REM\Models;

use Illuminate\Database\Eloquent\Model;

class RemData extends Model
{
    protected $fillable = [
        'rem_upload_id', 'section', 'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function remUpload()
    {
        return $this->belongsTo(RemUpload::class);
    }
}
