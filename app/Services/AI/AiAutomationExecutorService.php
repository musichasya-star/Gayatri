<?php

namespace App\Services\AI;

use App\Models\AiAutomationLog;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Followup;
use App\Services\CRM\BookingService;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use Illuminate\Support\Str;
use Throwable;

class AiAutomationExecutorService
{
    public function __construct(
        private readonly AiAutomationRuleService $ruleService,
        private readonly AiAutomationApprovalService $approvalService,
        private readonly BookingService $bookingService,
    ) {}

    public function process(AiExtractedData $extractedData): ?AiAutomationLog
    {
        $rule = $this->ruleService->matchingRule($extractedData);

        if (! $rule) {
            return $this->log($extractedData, null, 'skipped', null, null, 'No matching automation rule.');
        }

        return $this->processWithRule($extractedData, $rule);
    }

    public function processWithRule(AiExtractedData $extractedData, AiAutomationRule $rule): ?AiAutomationLog
    {
        if (! $rule->is_active) {
            return $this->log($extractedData, $rule, 'skipped', null, null, 'Rule is not active.');
        }

        try {
            if ($rule->mode === 'human_only') {
                return $this->log($extractedData, $rule, 'blocked', null, null, 'Rule requires human only.');
            }

            $proposedData = $this->proposedData($extractedData, $rule);

            if ($rule->target_entity === 'booking' && data_get($extractedData->raw_ai_response, 'booking_confirmation') !== 'confirmed') {
                $this->updateCustomerFromProposedData($extractedData, $proposedData['customer'] ?? []);
                $status = data_get($extractedData->raw_ai_response, 'booking_confirmation') === 'rejected' ? 'rejected' : 'awaiting_confirmation';
                $extractedData->update(['status' => $status]);

                return $this->log($extractedData, $rule, $status, $proposedData, null, $status === 'rejected' ? 'Customer rejected booking confirmation.' : 'Waiting customer booking confirmation.');
            }

            if ($rule->target_entity === 'booking' && $rule->action === 'cancel_booking' && ! $this->cancelRequiresApproval($proposedData)) {
                $output = $this->executeAutoCreate($extractedData, $rule, $proposedData);
                $extractedData->update(['status' => 'processed']);

                return $this->log($extractedData, $rule, 'processed', $output);
            }

            if ($rule->target_entity === 'booking' && (bool) config('crm.ai_data_automation.booking_requires_approval', true)) {
                if (in_array($rule->action, ['create_booking_draft', 'create_booking_confirmed'], true)) {
                    $proposedData = $this->withPendingBooking($extractedData, $proposedData);
                }

                $approval = $this->approvalService->create($extractedData, $rule, $proposedData);
                $extractedData->update(['status' => 'pending_approval']);

                return $this->log($extractedData, $rule, 'pending_approval', $proposedData, $approval->id);
            }

            if ($rule->mode === 'need_confirmation') {
                $approval = $this->approvalService->create($extractedData, $rule, $proposedData);
                $extractedData->update(['status' => 'pending_approval']);

                return $this->log($extractedData, $rule, 'pending_approval', $proposedData, $approval->id);
            }

            $output = $this->executeAutoCreate($extractedData, $rule, $proposedData);
            $extractedData->update(['status' => 'processed']);

            return $this->log($extractedData, $rule, 'processed', $output);
        } catch (Throwable $exception) {
            $extractedData->update(['status' => 'failed']);

            return $this->log($extractedData, $rule, 'failed', null, null, $exception->getMessage());
        }
    }

    private function executeAutoCreate(AiExtractedData $extractedData, AiAutomationRule $rule, array $proposedData): array
    {
        if ($rule->target_entity === 'customer' && in_array($rule->action, ['create_customer', 'update_customer'], true)) {
            $customer = $extractedData->customer;
            $customerData = $proposedData['customer'] ?? [];

            if ($customer && $customerData !== []) {
                $updates = [];
                if (! empty($customerData['name']) && $this->shouldUpdateCustomerName((string) $customer->name)) {
                    $updates['name'] = $customerData['name'];
                }
                if (! empty($customerData['address'])) {
                    $updates['address'] = $customerData['address'];
                }
                foreach (['phone', 'whatsapp_number'] as $field) {
                    if (! empty($customerData[$field])) {
                        $updates[$field] = $customerData[$field];
                    }
                }
                if (! empty($customerData['tags'])) {
                    $updates['tags'] = array_values(array_unique(array_merge($customer->tags ?? [], $customerData['tags'])));
                }
                $updates['status'] = CustomerStatus::LEAD;
                $customer->update($updates);
            }

            return ['customer_id' => $customer?->id, 'updated' => $customerData];
        }

        if ($rule->target_entity === 'followup' && $rule->action === 'create_followup') {
            $followup = Followup::create([
                'customer_id' => $extractedData->customer_id,
                'conversation_id' => $extractedData->conversation_id,
                'title' => 'Follow-up AI Automation',
                'notes' => data_get($proposedData, 'followup.reason', 'Follow-up dibuat dari AI automation.'),
                'status' => 'open',
                'priority' => 'normal',
                'due_at' => now()->addDay(),
            ]);

            return ['followup_id' => $followup->id];
        }

        if ($rule->target_entity === 'booking' && in_array($rule->action, ['create_booking_draft', 'create_booking_confirmed'], true)) {
            $booking = $proposedData['booking'] ?? [];
            $this->updateCustomerFromProposedData($extractedData, $proposedData['customer'] ?? []);

            $existingBooking = Booking::query()
                ->where(function ($query) use ($extractedData) {
                    $query->where('customer_id', $extractedData->customer_id)
                        ->orWhere('conversation_id', $extractedData->conversation_id);
                })
                ->where('service_id', $booking['service_id'] ?? null)
                ->whereDate('booking_date', $booking['booking_date'] ?? null)
                ->whereIn('start_time', [$booking['start_time'] ?? null, substr((string) ($booking['start_time'] ?? ''), 0, 5)])
                ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::NO_SHOW])
                ->latest('id')
                ->first();

            if ($existingBooking) {
                return ['booking_id' => $existingBooking->id, 'booking_code' => $existingBooking->booking_code, 'existing' => true];
            }

            $bookingModel = $this->bookingService->create([
                'customer_id' => $extractedData->customer_id,
                'conversation_id' => $extractedData->conversation_id,
                'branch_id' => $booking['branch_id'] ?? null,
                'service_id' => $booking['service_id'] ?? null,
                'therapist_id' => $booking['therapist_id'] ?? null,
                'availability_slot_id' => $booking['availability_slot_id'] ?? null,
                'booking_date' => $booking['booking_date'] ?? null,
                'start_time' => $booking['start_time'] ?? null,
                'addons' => $booking['addons'] ?? [],
                'status' => $rule->action === 'create_booking_confirmed' ? 'confirmed' : 'draft',
                'payment_status' => 'unpaid',
                'source' => 'ai_automation',
                'notes' => 'Booking dibuat otomatis dari AI automation extracted data #'.$extractedData->id,
            ], null);

            return ['booking_id' => $bookingModel->id, 'booking_code' => $bookingModel->booking_code];
        }

        if ($rule->target_entity === 'booking' && $rule->action === 'cancel_booking') {
            $booking = ! empty($proposedData['booking']['booking_id']) ? Booking::find($proposedData['booking']['booking_id']) : null;

            if (! $booking || ! $this->bookingMatchesExtractedData($booking, $extractedData)) {
                return ['cancelled' => false, 'reason' => 'Booking tidak ditemukan.'];
            }

            $cancelled = $this->bookingService->cancel($booking, BookingStatus::CANCELLED_BY_USER, 'Dibatalkan oleh customer melalui chat AI automation #'.$extractedData->id);

            return ['booking_id' => $cancelled->id, 'booking_code' => $cancelled->booking_code, 'status' => $cancelled->status];
        }

        return ['noop' => true, 'reason' => 'Unsupported auto_create action.'];
    }

    private function cancelRequiresApproval(array $proposedData): bool
    {
        return data_get($proposedData, 'booking.booking_status') === BookingStatus::CONFIRMED;
    }

    private function updateCustomerFromProposedData(AiExtractedData $extractedData, array $customerData): void
    {
        $customer = $extractedData->customer;
        if (! $customer || $customerData === []) {
            return;
        }

        $updates = [];
        if (! empty($customerData['name']) && $this->shouldUpdateCustomerName((string) $customer->name)) {
            $updates['name'] = $customerData['name'];
        }
        if (! empty($customerData['address']) && blank($customer->address)) {
            $updates['address'] = $customerData['address'];
        }
        if (! empty($customerData['phone'])) {
            $updates['phone'] = $customerData['phone'];
        }
        if (! empty($customerData['whatsapp_number']) && $this->canUseWhatsappNumber((string) $customerData['whatsapp_number'], $customer->id)) {
            $updates['whatsapp_number'] = $customerData['whatsapp_number'];
        }
        if (! empty($customerData['tags'])) {
            $updates['tags'] = array_values(array_unique(array_merge($customer->tags ?? [], $customerData['tags'])));
        }

        if ($updates !== []) {
            $updates['status'] = CustomerStatus::LEAD;
            $customer->update($updates);
        }
    }

    private function withPendingBooking(AiExtractedData $extractedData, array $proposedData): array
    {
        $booking = $proposedData['booking'] ?? [];

        if (! empty($booking['booking_id'])) {
            return $proposedData;
        }

        $existingBooking = Booking::query()
            ->where(function ($query) use ($extractedData) {
                $query->where('customer_id', $extractedData->customer_id)
                    ->orWhere('conversation_id', $extractedData->conversation_id);
            })
            ->where('service_id', $booking['service_id'] ?? null)
            ->whereDate('booking_date', $booking['booking_date'] ?? null)
            ->whereIn('start_time', [$booking['start_time'] ?? null, substr((string) ($booking['start_time'] ?? ''), 0, 5)])
            ->whereNotIn('status', [BookingStatus::CANCELLED, BookingStatus::CANCELLED_BY_USER, BookingStatus::NO_SHOW])
            ->latest('id')
            ->first();

        if (! $existingBooking) {
            $this->updateCustomerFromProposedData($extractedData, $proposedData['customer'] ?? []);
            $existingBooking = $this->bookingService->create([
                'customer_id' => $extractedData->customer_id,
                'conversation_id' => $extractedData->conversation_id,
                'branch_id' => $booking['branch_id'] ?? null,
                'service_id' => $booking['service_id'] ?? null,
                'therapist_id' => $booking['therapist_id'] ?? null,
                'availability_slot_id' => $booking['availability_slot_id'] ?? null,
                'booking_date' => $booking['booking_date'] ?? null,
                'start_time' => $booking['start_time'] ?? null,
                'addons' => $booking['addons'] ?? [],
                'status' => BookingStatus::PENDING,
                'payment_status' => 'unpaid',
                'source' => 'ai_approval',
                'notes' => 'Booking pending approval dari AI automation extracted data #'.$extractedData->id,
            ], null);
        }

        $proposedData['booking']['booking_id'] = $existingBooking->id;
        $proposedData['booking']['booking_code'] = $existingBooking->booking_code;

        return $proposedData;
    }

    private function shouldUpdateCustomerName(string $currentName): bool
    {
        $name = Str::of($currentName)->lower()->squish()->toString();

        return blank($name)
            || str_starts_with($currentName, 'Customer ')
            || Str::contains($name, ['mau ', 'ingin ', 'tanya ', 'booking', 'reservasi', 'baby spa', 'mom massage', 'hari ini', 'besok', 'tanggal', ' jam', ' pukul']);
    }

    private function canUseWhatsappNumber(string $whatsappNumber, int $customerId): bool
    {
        return ! Customer::query()
            ->where('whatsapp_number', $whatsappNumber)
            ->whereKeyNot($customerId)
            ->exists();
    }

    private function bookingMatchesExtractedData(Booking $booking, AiExtractedData $extractedData): bool
    {
        return $booking->customer_id === $extractedData->customer_id
            || ($booking->conversation_id !== null && $booking->conversation_id === $extractedData->conversation_id);
    }

    private function proposedData(AiExtractedData $extractedData, AiAutomationRule $rule): array
    {
        return array_filter([
            'customer' => $extractedData->extracted_customer_data,
            'booking' => $extractedData->extracted_booking_data,
            'followup' => $extractedData->extracted_followup_data,
            'intent' => $extractedData->intent,
            'confidence_score' => (float) $extractedData->confidence_score,
            'missing_fields' => $extractedData->missing_fields,
            'rule' => ['id' => $rule->id, 'name' => $rule->name],
        ], fn ($value) => $value !== null && $value !== []);
    }

    private function log(AiExtractedData $extractedData, ?AiAutomationRule $rule, string $status, ?array $outputPayload = null, ?int $approvalId = null, ?string $error = null): AiAutomationLog
    {
        return AiAutomationLog::create([
            'ai_automation_rule_id' => $rule?->id,
            'ai_extracted_data_id' => $extractedData->id,
            'ai_automation_approval_id' => $approvalId,
            'conversation_id' => $extractedData->conversation_id,
            'message_id' => $extractedData->message_id,
            'customer_id' => $extractedData->customer_id,
            'target_entity' => $rule?->target_entity,
            'action' => $rule?->action,
            'mode' => $rule?->mode,
            'status' => $status,
            'input_payload' => $extractedData->raw_ai_response,
            'output_payload' => $outputPayload,
            'error_message' => $error,
            'created_by_ai' => true,
        ]);
    }
}
