<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiPersona extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'prompt',
        'tone',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function aiLogs(): HasMany
    {
        return $this->hasMany(AiLog::class, 'persona_id');
    }
}
