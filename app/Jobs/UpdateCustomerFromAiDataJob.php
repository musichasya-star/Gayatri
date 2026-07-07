<?php

namespace App\Jobs;

use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Services\AI\AiAutomationExecutorService;
use App\Services\AI\AiAutomationRuleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class UpdateCustomerFromAiDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $extractedDataId, private readonly ?int $ruleId = null) {}

    public function handle(AiAutomationExecutorService $executorService, AiAutomationRuleService $ruleService): void
    {
        $extractedData = AiExtractedData::find($this->extractedDataId);

        if (! $extractedData) {
            return;
        }

        $rule = $this->resolveRule($extractedData, $ruleService);

        if (! $rule || $rule->target_entity !== 'customer' || $rule->action !== 'update_customer') {
            return;
        }

        $executorService->processWithRule($extractedData, $rule);
    }

    private function resolveRule(AiExtractedData $extractedData, AiAutomationRuleService $ruleService): ?AiAutomationRule
    {
        if ($this->ruleId !== null) {
            $rule = AiAutomationRule::query()->where('is_active', true)->find($this->ruleId);

            if ($rule) {
                return $rule;
            }
        }

        return $ruleService->matchingRule($extractedData);
    }
}
