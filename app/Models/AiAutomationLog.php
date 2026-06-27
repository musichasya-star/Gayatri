<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAutomationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_automation_rule_id', 'ai_extracted_data_id', 'ai_automation_approval_id',
        'conversation_id', 'message_id', 'customer_id', 'target_entity', 'action', 'mode',
        'status', 'input_payload', 'output_payload', 'error_message', 'created_by_ai', 'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'input_payload' => 'array',
            'output_payload' => 'array',
            'created_by_ai' => 'boolean',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AiAutomationRule::class, 'ai_automation_rule_id');
    }
}
