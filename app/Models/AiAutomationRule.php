<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'trigger_event', 'target_entity', 'action', 'mode', 'confidence_threshold',
        'required_fields', 'forbidden_intents', 'conditions', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'confidence_threshold' => 'decimal:2',
            'required_fields' => 'array',
            'forbidden_intents' => 'array',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
