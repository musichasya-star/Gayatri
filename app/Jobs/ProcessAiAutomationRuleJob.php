<?php

namespace App\Jobs;

use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Services\AI\AiAutomationExecutorService;
use App\Services\AI\AiAutomationRuleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessAiAutomationRuleJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $extractedDataId) {}

    public function handle(AiAutomationExecutorService $executorService, AiAutomationRuleService $ruleService): void
    {
        $extractedData = AiExtractedData::find($this->extractedDataId);

        if (! $extractedData) {
            return;
        }

        $rule = $ruleService->matchingRule($extractedData);

        if (! $rule) {
            $executorService->process($extractedData);

            return;
        }

        if ($rule->mode === 'human_only') {
            $executorService->processWithRule($extractedData, $rule);

            return;
        }

        if ($this->requiresApproval($rule)) {
            CreateAiAutomationApprovalJob::dispatch($extractedData->id, $rule->id);

            return;
        }

        if ($rule->target_entity === 'customer') {
            if ($rule->action === 'create_customer') {
                CreateCustomerFromAiDataJob::dispatch($extractedData->id, $rule->id);

                return;
            }

            if ($rule->action === 'update_customer') {
                UpdateCustomerFromAiDataJob::dispatch($extractedData->id, $rule->id);

                return;
            }
        }

        if ($rule->target_entity === 'booking') {
            CreateBookingFromAiDataJob::dispatch($extractedData->id, $rule->id);

            return;
        }

        if ($rule->target_entity === 'followup') {
            CreateFollowupFromAiDataJob::dispatch($extractedData->id, $rule->id);

            return;
        }

        if ($rule->target_entity === 'reminder') {
            CreateReminderFromAiDataJob::dispatch($extractedData->id, $rule->id);

            return;
        }

        $executorService->processWithRule($extractedData, $rule);
    }

    private function requiresApproval(AiAutomationRule $rule): bool
    {
        if ($rule->mode === 'need_confirmation') {
            return true;
        }

        return $rule->target_entity === 'booking'
            && in_array($rule->action, ['create_booking_draft', 'create_booking_confirmed'], true)
            && (bool) config('crm.ai_data_automation.booking_requires_approval', true);
    }
}
