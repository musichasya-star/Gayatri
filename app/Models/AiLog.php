<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'message_id',
        'customer_id',
        'persona_id',
        'knowledge_base_id',
        'prompt',
        'response',
        'confidence',
        'status',
        'fallback_reason',
        'sources',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'sources' => 'array',
            'meta' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(AiPersona::class, 'persona_id');
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
