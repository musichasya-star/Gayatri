<?php

namespace App\Jobs;

use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Booking;
use App\Models\Reminder;
use App\Services\AI\AiAutomationRuleService;
use App\Support\ReminderStatus;
use App\Support\ReminderType;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CreateReminderFromAiDataJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $extractedDataId, private readonly ?int $ruleId = null) {}

    public function handle(AiAutomationRuleService $ruleService): void
    {
        $extractedData = AiExtractedData::find($this->extractedDataId);

        if (! $extractedData) {
            return;
        }

        $rule = $this->resolveRule($extractedData, $ruleService);

        if (! $rule || $rule->target_entity !== 'reminder') {
            return;
        }

        if (! in_array($rule->action, ['create_reminder', 'create_h1_reminder', 'create_h0_reminder'], true)) {
            return;
        }

        $reminderType = $this->resolveReminderType($extractedData, $rule);
        $booking = $this->resolveBooking($extractedData);
        $scheduledAt = $this->resolveScheduledAt($extractedData, $reminderType, $booking);

        Reminder::create([
            'booking_id' => $booking?->id,
            'customer_id' => $extractedData->customer_id,
            'conversation_id' => $extractedData->conversation_id,
            'type' => $reminderType,
            'channel' => 'whatsapp',
            'status' => ReminderStatus::SCHEDULED,
            'scheduled_at' => $scheduledAt,
            'message' => $this->resolveMessage($extractedData),
            'payload' => [
                'source' => 'ai_automation',
                'rule_id' => $rule->id,
                'extracted_data_id' => $extractedData->id,
            ],
        ]);
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

    private function resolveReminderType(AiExtractedData $extractedData, AiAutomationRule $rule): string
    {
        $reminderTypes = ReminderType::all();

        $customType = data_get($extractedData->extracted_followup_data, 'reminder_type')
            ?: data_get($extractedData->raw_ai_response, 'reminder.type');

        if (is_string($customType) && in_array($customType, $reminderTypes, true)) {
            return $customType;
        }

        if (in_array($rule->action, ['create_h1_reminder', 'create_h0_reminder'], true)) {
            return $rule->action === 'create_h1_reminder' ? ReminderType::H1 : ReminderType::H0;
        }

        return ReminderType::FOLLOWUP;
    }

    private function resolveBooking(AiExtractedData $extractedData): ?Booking
    {
        $bookingId = data_get($extractedData->extracted_booking_data, 'booking_id')
            ?: data_get($extractedData->raw_ai_response, 'booking.booking_id');

        if (! $bookingId) {
            return null;
        }

        return Booking::find($bookingId);
    }

    private function resolveScheduledAt(AiExtractedData $extractedData, string $reminderType, ?Booking $booking): Carbon
    {
        $scheduledAt = $this->resolveScheduledAtFromPayload($extractedData);

        if ($scheduledAt !== null) {
            return $scheduledAt->lessThan(now()) ? now() : $scheduledAt;
        }

        if (! $booking || ! $booking->booking_date || ! $booking->start_time) {
            return now();
        }

        $bookingAt = Carbon::parse($booking->booking_date)
            ->setTimeFromTimeString(substr((string) $booking->start_time, 0, 8));

        return match ($reminderType) {
            ReminderType::H1 => $bookingAt->subDay(),
            ReminderType::H0 => $bookingAt,
            default => now(),
        };
    }

    private function resolveScheduledAtFromPayload(AiExtractedData $extractedData): ?Carbon
    {
        $payloadSources = [
            $extractedData->extracted_followup_data,
            data_get($extractedData->raw_ai_response, 'followup', []),
            data_get($extractedData->raw_ai_response, 'reminder', []),
            $extractedData->raw_ai_response,
        ];

        foreach ($payloadSources as $payload) {
            $scheduledAtRaw = data_get((array) $payload, 'scheduled_at')
                ?: data_get((array) $payload, 'due_at');

            if (! is_string($scheduledAtRaw)) {
                continue;
            }

            try {
                return Carbon::parse($scheduledAtRaw);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveMessage(AiExtractedData $extractedData): ?string
    {
        $reason = (string) (data_get($extractedData->extracted_followup_data, 'reason')
            ?: data_get($extractedData->raw_ai_response, 'followup.reason')
            ?: 'Follow-up dari AI Automation.');

        return trim(($reason ?: 'Follow-up dari AI Automation.') . ' Untuk Bunda '.$extractedData->customer?->name .'.');
    }
}
