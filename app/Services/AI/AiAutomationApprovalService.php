<?php

namespace App\Services\AI;

use App\Models\AiAutomationApproval;
use App\Models\AiAutomationLog;
use App\Models\AiAutomationRule;
use App\Models\AiExtractedData;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\User;
use App\Services\CRM\BookingService;
use App\Support\BookingStatus;
use App\Support\CustomerStatus;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AiAutomationApprovalService
{
    public function __construct(private readonly BookingService $bookingService) {}

    public function create(AiExtractedData $extractedData, AiAutomationRule $rule, array $proposedData): AiAutomationApproval
    {
        return AiAutomationApproval::firstOrCreate([
            'ai_extracted_data_id' => $extractedData->id,
            'target_entity' => $rule->target_entity,
            'action' => $rule->action,
            'status' => 'pending',
        ], [
            'ai_extracted_data_id' => $extractedData->id,
            'customer_id' => $extractedData->customer_id,
            'conversation_id' => $extractedData->conversation_id,
            'target_entity' => $rule->target_entity,
            'action' => $rule->action,
            'mode' => $rule->mode,
            'proposed_data' => $proposedData,
            'expires_at' => now()->addHours((int) config('crm.ai_data_automation.approval_expire_hours', 24)),
        ]);
    }

    public function approve(AiAutomationApproval $approval, User $user, ?array $editedData = null): array
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Approval sudah diproses.');
        }

        $data = $editedData ?: $approval->proposed_data;
        $output = match ($approval->target_entity) {
            'booking' => $this->approveBooking($approval, $user, $data),
            'customer' => $this->approveCustomerUpdate($approval, $data),
            default => ['noop' => true, 'reason' => 'Unsupported approval target.'],
        };

        $approval->update([
            'status' => 'approved',
            'approved_by' => $user->id,
            'approved_at' => now(),
            'edited_data' => $editedData,
        ]);

        $approval->extractedData?->update(['status' => 'approved']);

        AiAutomationLog::create([
            'ai_extracted_data_id' => $approval->ai_extracted_data_id,
            'ai_automation_approval_id' => $approval->id,
            'conversation_id' => $approval->conversation_id,
            'customer_id' => $approval->customer_id,
            'target_entity' => $approval->target_entity,
            'action' => $approval->action,
            'mode' => $approval->mode,
            'status' => 'approved',
            'input_payload' => $data,
            'output_payload' => $output,
            'created_by_ai' => false,
            'approved_by' => $user->id,
        ]);

        return $output;
    }

    public function reject(AiAutomationApproval $approval, User $user, string $reason): void
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Approval sudah diproses.');
        }

        $this->rejectPendingBooking($approval, $reason);

        $approval->update([
            'status' => 'rejected',
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        $approval->extractedData?->update(['status' => 'rejected']);

        AiAutomationLog::create([
            'ai_extracted_data_id' => $approval->ai_extracted_data_id,
            'ai_automation_approval_id' => $approval->id,
            'conversation_id' => $approval->conversation_id,
            'customer_id' => $approval->customer_id,
            'target_entity' => $approval->target_entity,
            'action' => $approval->action,
            'mode' => $approval->mode,
            'status' => 'rejected',
            'input_payload' => $approval->proposed_data,
            'error_message' => $reason,
            'created_by_ai' => false,
            'approved_by' => $user->id,
        ]);
    }

    private function approveBooking(AiAutomationApproval $approval, User $user, array $data): array
    {
        if ($approval->action === 'reschedule_booking') {
            return $this->approveBookingReschedule($approval, $data);
        }

        if ($approval->action === 'cancel_booking') {
            return $this->approveBookingCancel($approval, $data);
        }

        $booking = $data['booking'] ?? [];
        $this->updateCustomerFromProposedData($approval, $data['customer'] ?? []);
        $service = ! empty($booking['service_id']) ? Service::find($booking['service_id']) : null;

        if (! $service || empty($booking['booking_date']) || empty($booking['start_time'])) {
            throw new InvalidArgumentException('Data booking belum lengkap.');
        }

        $existingBooking = ! empty($booking['booking_id']) ? Booking::find($booking['booking_id']) : null;
        $approvedStatus = in_array($approval->action, ['create_booking_draft', 'create_booking_confirmed'], true)
            ? BookingStatus::CONFIRMED
            : BookingStatus::DRAFT;
        if ($existingBooking) {
            if (! $this->bookingMatchesApproval($existingBooking, $approval)) {
                throw new InvalidArgumentException('Booking pending tidak sesuai customer.');
            }

            $updated = $this->bookingService->update($existingBooking, [
                'customer_id' => $existingBooking->customer_id,
                'service_id' => $service->id,
                'branch_id' => $booking['branch_id'] ?? $service->branch_id,
                'therapist_id' => $booking['therapist_id'] ?? null,
                'promo_id' => $existingBooking->promo_id,
                'availability_slot_id' => $booking['availability_slot_id'] ?? null,
                'booking_date' => $booking['booking_date'],
                'start_time' => $booking['start_time'],
                'addons' => $booking['addons'] ?? [],
                'status' => $approvedStatus,
                'payment_status' => $existingBooking->payment_status,
                'notes' => trim(($existingBooking->notes ? $existingBooking->notes."\n" : '').'Booking disetujui dari AI Data Automation approval #'.$approval->id),
            ]);

            return ['booking_id' => $updated->id, 'booking_code' => $updated->booking_code, 'approved_existing' => true];
        }

        $bookingModel = $this->bookingService->create([
            'customer_id' => $approval->customer_id,
            'conversation_id' => $approval->conversation_id,
            'service_id' => $service->id,
            'branch_id' => $booking['branch_id'] ?? $service->branch_id,
            'therapist_id' => $booking['therapist_id'] ?? null,
            'availability_slot_id' => $booking['availability_slot_id'] ?? null,
            'booking_date' => $booking['booking_date'],
            'start_time' => $booking['start_time'],
            'addons' => $booking['addons'] ?? [],
            'status' => $approvedStatus,
            'payment_status' => 'unpaid',
            'source' => 'ai_approval',
            'notes' => 'Booking dibuat dari AI Data Automation approval #'.$approval->id,
        ], $user->id);

        return ['booking_id' => $bookingModel->id, 'booking_code' => $bookingModel->booking_code];
    }

    private function rejectPendingBooking(AiAutomationApproval $approval, string $reason): void
    {
        if ($approval->target_entity !== 'booking' || ! in_array($approval->action, ['create_booking_draft', 'create_booking_confirmed'], true)) {
            return;
        }

        $bookingId = data_get($approval->proposed_data, 'booking.booking_id');
        $booking = $bookingId ? Booking::find($bookingId) : null;
        if (! $booking || ! $this->bookingMatchesApproval($booking, $approval) || $booking->status !== BookingStatus::PENDING) {
            return;
        }

        $this->bookingService->cancel($booking, BookingStatus::CANCELLED, 'Booking pending approval ditolak admin: '.$reason);
    }

    private function approveBookingReschedule(AiAutomationApproval $approval, array $data): array
    {
        $bookingData = $data['booking'] ?? [];
        $bookingModel = $this->bookingFromApprovalData($bookingData);

        if (! $bookingModel || ! $this->bookingMatchesApproval($bookingModel, $approval)) {
            throw new InvalidArgumentException('Booking yang akan diubah tidak ditemukan.');
        }

        if (empty($bookingData['booking_date']) || empty($bookingData['start_time'])) {
            throw new InvalidArgumentException('Data jadwal baru belum lengkap.');
        }

        $updated = $this->bookingService->update($bookingModel, [
            'customer_id' => $bookingModel->customer_id,
            'branch_id' => $bookingData['branch_id'] ?? $bookingModel->branch_id,
            'service_id' => $bookingData['service_id'] ?? $bookingModel->service_id,
            'therapist_id' => $bookingData['therapist_id'] ?? $bookingModel->therapist_id,
            'promo_id' => $bookingModel->promo_id,
            'availability_slot_id' => $bookingData['availability_slot_id'] ?? null,
            'booking_date' => $bookingData['booking_date'],
            'start_time' => $bookingData['start_time'],
            'status' => $bookingModel->status,
            'payment_status' => $bookingModel->payment_status,
            'notes' => trim(($bookingModel->notes ? $bookingModel->notes."\n" : '').'Reschedule dari AI Data Automation approval #'.$approval->id),
        ]);

        return [
            'booking_id' => $updated->id,
            'booking_code' => $updated->booking_code,
            'rescheduled' => true,
            'booking_date' => $updated->booking_date?->toDateString(),
            'start_time' => substr((string) $updated->start_time, 0, 8),
        ];
    }

    private function approveBookingCancel(AiAutomationApproval $approval, array $data): array
    {
        $bookingData = $data['booking'] ?? [];
        $bookingModel = $this->bookingFromApprovalData($bookingData);

        if (! $bookingModel || ! $this->bookingMatchesApproval($bookingModel, $approval)) {
            throw new InvalidArgumentException('Booking yang akan dibatalkan tidak ditemukan.');
        }

        $cancelled = $this->bookingService->cancel($bookingModel, BookingStatus::CANCELLED, 'Pembatalan customer disetujui admin dari AI Data Automation approval #'.$approval->id);

        return [
            'booking_id' => $cancelled->id,
            'booking_code' => $cancelled->booking_code,
            'cancelled' => true,
            'status' => $cancelled->status,
        ];
    }

    private function updateCustomerFromProposedData(AiAutomationApproval $approval, array $customerData): void
    {
        $customer = $approval->customer;
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
        if (! empty($customerData['whatsapp_number']) && ! Customer::query()->where('whatsapp_number', $customerData['whatsapp_number'])->whereKeyNot($customer->id)->exists()) {
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

    private function bookingFromApprovalData(array $bookingData): ?Booking
    {
        if (! empty($bookingData['booking_id'])) {
            $booking = Booking::find($bookingData['booking_id']);

            if ($booking) {
                return $booking;
            }
        }

        if (! empty($bookingData['booking_code'])) {
            return Booking::where('booking_code', $bookingData['booking_code'])->latest('id')->first();
        }

        return null;
    }

    private function bookingMatchesApproval(Booking $booking, AiAutomationApproval $approval): bool
    {
        return $booking->customer_id === $approval->customer_id
            || ($booking->conversation_id !== null && $booking->conversation_id === $approval->conversation_id);
    }

    private function approveCustomerUpdate(AiAutomationApproval $approval, array $data): array
    {
        $customer = $approval->customer;
        $customerData = $data['customer'] ?? [];

        if (! $customer || $customerData === []) {
            throw new InvalidArgumentException('Data customer belum lengkap.');
        }

        $updates = [];
        foreach (['name', 'address', 'city'] as $field) {
            if (! empty($customerData[$field])) {
                $updates[$field] = $customerData[$field];
            }
        }
        if (! empty($customerData['tags'])) {
            $updates['tags'] = array_values(array_unique(array_merge($customer->tags ?? [], $customerData['tags'])));
        }
        $updates['status'] = CustomerStatus::LEAD;

        $customer->update($updates);

        return ['customer_id' => $customer->id, 'updated' => $updates];
    }

    private function shouldUpdateCustomerName(string $currentName): bool
    {
        $name = Str::of($currentName)->lower()->squish()->toString();

        return blank($name)
            || str_starts_with($currentName, 'Customer ')
            || Str::contains($name, ['mau ', 'ingin ', 'tanya ', 'booking', 'reservasi', 'baby spa', 'mom massage', 'hari ini', 'besok', 'tanggal', ' jam', ' pukul']);
    }
}
