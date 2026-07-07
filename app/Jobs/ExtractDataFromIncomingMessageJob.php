<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AI\AiDataExtractionService;
use App\Services\AI\ConversationFlowService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractDataFromIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $messageId) {}

    public function handle(AiDataExtractionService $extractionService, ConversationFlowService $flowService): void
    {
        if (! (bool) config('crm.ai_data_automation.enabled', true)) {
            return;
        }

        $message = Message::with(['conversation.customer'])->find($this->messageId);

        if (! $message || $message->direction !== 'incoming' || $message->message_type !== 'text') {
            return;
        }

        if ($flowService->shouldSkipExtraction($message)) {
            return;
        }

        $extractedData = $extractionService->extractFromMessage($message);
        ProcessAiAutomationRuleJob::dispatch($extractedData->id);
    }
}
