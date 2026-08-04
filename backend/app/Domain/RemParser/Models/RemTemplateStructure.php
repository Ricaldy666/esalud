<?php

namespace App\Domain\RemParser\Models;

use App\Domain\REM\Models\RemTemplate;
use App\Domain\REM\Models\RemUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class RemTemplateStructure extends Model
{
    use SoftDeletes;

    protected $table = 'rem_template_structures';

    protected $fillable = [
        'rem_upload_id',
        'rem_template_id',
        'anio',
        'serie',
        'hash_estructura',
        'version_number',
        'estructura',
        'metadata',
        'source_filename',
        'status',
        'approved_at',
        'approved_by',
        'superseded_by_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'version_number' => 'integer',
            'estructura' => 'array',
            'metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function remUpload()
    {
        return $this->belongsTo(RemUpload::class);
    }

    public function remTemplate()
    {
        return $this->belongsTo(RemTemplate::class);
    }

    public function supersededBy()
    {
        return $this->belongsTo(__CLASS__, 'superseded_by_id');
    }

    public function supersedes()
    {
        return $this->hasMany(__CLASS__, 'superseded_by_id');
    }
}
