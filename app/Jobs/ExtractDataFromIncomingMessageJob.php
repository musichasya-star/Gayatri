<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\AI\AiAutomationExecutorService;
use App\Services\AI\AiDataExtractionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractDataFromIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $messageId) {}

    public function handle(AiDataExtractionService $extractionService, AiAutomationExecutorService $executorService): void
    {
        if (! (bool) config('crm.ai_data_automation.enabled', true)) {
            return;
        }

        $message = Message::with(['conversation.customer'])->find($this->messageId);

        if (! $message || $message->direction !== 'incoming' || $message->message_type !== 'text') {
            return;
        }

        $extractedData = $extractionService->extractFromMessage($message);
        $executorService->process($extractedData);
    }
}
