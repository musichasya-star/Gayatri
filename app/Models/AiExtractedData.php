<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiExtractedData extends Model
{
    use HasFactory;

    protected $table = 'ai_extracted_data';

    protected $fillable = [
        'conversation_id', 'message_id', 'customer_id', 'intent', 'confidence_score',
        'extracted_customer_data', 'extracted_booking_data', 'extracted_followup_data',
        'missing_fields', 'raw_ai_response', 'status',
    ];

    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'extracted_customer_data' => 'array',
            'extracted_booking_data' => 'array',
            'extracted_followup_data' => 'array',
            'missing_fields' => 'array',
            'raw_ai_response' => 'array',
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

    public function approvals(): HasMany
    {
        return $this->hasMany(AiAutomationApproval::class, 'ai_extracted_data_id');
    }
}
