<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationFlow extends Model
{
    protected $fillable = [
        'conversation_id',
        'customer_id',
        'last_message_id',
        'completed_message_id',
        'intent',
        'step',
        'status',
        'payload',
        'attempts',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
