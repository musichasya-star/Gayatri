<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationApproval extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_extracted_data_id', 'customer_id', 'conversation_id', 'target_entity', 'action', 'mode',
        'proposed_data', 'status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
        'rejection_reason', 'edited_data', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'proposed_data' => 'array',
            'edited_data' => 'array',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function extractedData(): BelongsTo
    {
        return $this->belongsTo(AiExtractedData::class, 'ai_extracted_data_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
