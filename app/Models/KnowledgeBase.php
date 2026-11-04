<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'type',
        'source_path',
        'mime_type',
        'content',
        'status',
        'valid_from',
        'valid_until',
        'meta',
        'publication_status', 'audience', 'reviewed_by', 'reviewed_at', 'revision', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'meta' => 'array',
            'reviewed_at' => 'datetime',
            'revision' => 'integer',
            'is_demo' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(KnowledgeDomain::class);
    }

    public function aiLogs(): HasMany
    {
        return $this->hasMany(AiLog::class);
    }
}
