<?php

namespace App\Services\AI;

use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;

class AiAutomationRuleService
{
    public function matchingRule(AiExtractedData $extractedData, string $triggerEvent = 'message_extracted'): ?AiAutomationRule
    {
        $rule = AiAutomationRule::query()
            ->where('is_active', true)
            ->where('trigger_event', $triggerEvent)
            ->where('confidence_threshold', '<=', $extractedData->confidence_score)
            ->orderByDesc('confidence_threshold')
            ->get()
            ->first(fn (AiAutomationRule $rule) => $this->matches($rule, $extractedData));

        if ($rule) {
            return $rule;
        }

        if ($extractedData->intent === 'booking_request' && (bool) config('crm.ai_data_automation.auto_create_booking', true)) {
            $defaultRule = $this->defaultBookingRule();

            if ((float) $defaultRule->confidence_threshold <= (float) $extractedData->confidence_score && $this->matches($defaultRule, $extractedData)) {
                return $defaultRule;
            }
        }

        if ($extractedData->intent === 'booking_reschedule_request' && (bool) config('crm.ai_data_automation.auto_create_booking', true)) {
            $defaultRule = $this->defaultRescheduleRule();

            if ((float) $defaultRule->confidence_threshold <= (float) $extractedData->confidence_score && $this->matches($defaultRule, $extractedData)) {
                return $defaultRule;
            }
        }

        if ($extractedData->intent === 'booking_cancel_request' && (bool) config('crm.ai_data_automation.auto_create_booking', true)) {
            $defaultRule = $this->defaultCancelRule();

            if ((float) $defaultRule->confidence_threshold <= (float) $extractedData->confidence_score && $this->matches($defaultRule, $extractedData)) {
                return $defaultRule;
            }
        }

        return null;
    }

    private function defaultBookingRule(): AiAutomationRule
    {
        return AiAutomationRule::updateOrCreate([
            'name' => 'Default AI Booking Slot Automation',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'create_booking_draft',
        ], [
            'mode' => 'auto_create',
            'confidence_threshold' => (float) config('crm.ai_data_automation.booking_confidence_threshold', 0.85),
            'required_fields' => ['name', 'address', 'service_id', 'booking_date', 'start_time', 'availability_slot_id'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_request']],
            'is_active' => true,
        ]);
    }

    private function defaultRescheduleRule(): AiAutomationRule
    {
        return AiAutomationRule::updateOrCreate([
            'name' => 'Default AI Booking Reschedule Automation',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'reschedule_booking',
        ], [
            'mode' => 'need_confirmation',
            'confidence_threshold' => (float) config('crm.ai_data_automation.booking_confidence_threshold', 0.85),
            'required_fields' => ['booking_id', 'service_id', 'booking_date', 'start_time', 'availability_slot_id'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_reschedule_request']],
            'is_active' => true,
        ]);
    }

    private function defaultCancelRule(): AiAutomationRule
    {
        return AiAutomationRule::updateOrCreate([
            'name' => 'Default AI Booking Cancel Automation',
            'trigger_event' => 'message_extracted',
            'target_entity' => 'booking',
            'action' => 'cancel_booking',
        ], [
            'mode' => 'need_confirmation',
            'confidence_threshold' => (float) config('crm.ai_data_automation.booking_confidence_threshold', 0.85),
            'required_fields' => ['booking_id'],
            'forbidden_intents' => ['medical', 'refund', 'complaint'],
            'conditions' => ['intents' => ['booking_cancel_request']],
            'is_active' => true,
        ]);
    }

    private function matches(AiAutomationRule $rule, AiExtractedData $extractedData): bool
    {
        if (in_array($extractedData->intent, $rule->forbidden_intents ?? [], true)) {
            return false;
        }

        $conditions = $rule->conditions ?? [];
        if (! empty($conditions['intents']) && ! in_array($extractedData->intent, $conditions['intents'], true)) {
            return false;
        }

        foreach ($rule->required_fields ?? [] as $field) {
            if (! $this->hasField($extractedData, $field)) {
                return false;
            }
        }

        return true;
    }

    private function hasField(AiExtractedData $extractedData, string $field): bool
    {
        $customer = $extractedData->extracted_customer_data ?? [];
        $booking = $extractedData->extracted_booking_data ?? [];
        $followup = $extractedData->extracted_followup_data ?? [];

        return data_get($customer, $field) !== null
            || data_get($booking, $field) !== null
            || data_get($followup, $field) !== null;
    }
}
