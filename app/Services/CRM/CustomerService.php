<?php

namespace App\Services\CRM;

use App\Models\AiAutomationApproval;
use App\Models\AiAutomationLog;
use App\Models\AiExtractedData;
use App\Models\AiLog;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly BookingService $bookingService,
    ) {}

    public function create(array $data, ?User $actor, Request $request): Customer
    {
        $customer = Customer::create($data);

        $this->auditLogService->log(
            $actor,
            'customer.created',
            $customer,
            $request,
            [],
            $customer->fresh()->toArray(),
            'Membuat data customer baru.'
        );

        return $customer;
    }

    public function update(Customer $customer, array $data, ?User $actor, Request $request): Customer
    {
        $oldValues = $customer->getOriginal();
        $customer->update($data);

        $this->auditLogService->log(
            $actor,
            'customer.updated',
            $customer,
            $request,
            $oldValues,
            $customer->fresh()->toArray(),
            'Memperbarui data customer.'
        );

        return $customer;
    }

    public function archive(Customer $customer, ?User $actor, Request $request): Customer
    {
        $oldValues = $customer->getOriginal();
        $customer->update(['status' => 'inactive']);

        $this->auditLogService->log(
            $actor,
            'customer.archived',
            $customer,
            $request,
            $oldValues,
            $customer->fresh()->toArray(),
            'Mengarsipkan data customer.'
        );

        return $customer;
    }

    public function deleteWithRelations(Customer $customer, ?User $actor, Request $request): void
    {
        $oldValues = $customer->fresh()->toArray();

        DB::transaction(function () use ($customer): void {
            $conversationIds = $customer->conversations()->pluck('id');
            $messageIds = Message::query()
                ->where('customer_id', $customer->id)
                ->when($conversationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('conversation_id', $conversationIds))
                ->pluck('id');
            $extractedIds = AiExtractedData::query()
                ->where('customer_id', $customer->id)
                ->when($conversationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('conversation_id', $conversationIds))
                ->when($messageIds->isNotEmpty(), fn ($query) => $query->orWhereIn('message_id', $messageIds))
                ->pluck('id');
            $approvalIds = AiAutomationApproval::query()
                ->where('customer_id', $customer->id)
                ->when($conversationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('conversation_id', $conversationIds))
                ->when($extractedIds->isNotEmpty(), fn ($query) => $query->orWhereIn('ai_extracted_data_id', $extractedIds))
                ->pluck('id');

            AiAutomationLog::query()
                ->where('customer_id', $customer->id)
                ->when($conversationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('conversation_id', $conversationIds))
                ->when($messageIds->isNotEmpty(), fn ($query) => $query->orWhereIn('message_id', $messageIds))
                ->when($extractedIds->isNotEmpty(), fn ($query) => $query->orWhereIn('ai_extracted_data_id', $extractedIds))
                ->when($approvalIds->isNotEmpty(), fn ($query) => $query->orWhereIn('ai_automation_approval_id', $approvalIds))
                ->delete();
            AiAutomationApproval::whereIn('id', $approvalIds)->delete();
            AiExtractedData::whereIn('id', $extractedIds)->delete();
            AiLog::query()
                ->where('customer_id', $customer->id)
                ->when($conversationIds->isNotEmpty(), fn ($query) => $query->orWhereIn('conversation_id', $conversationIds))
                ->when($messageIds->isNotEmpty(), fn ($query) => $query->orWhereIn('message_id', $messageIds))
                ->delete();

            $customer->bookings()->with('availabilitySlot')->get()->each(function ($booking): void {
                $this->bookingService->delete($booking);
            });

            $customer->delete();
        });

        $this->auditLogService->log(
            $actor,
            'customer.deleted',
            null,
            $request,
            $oldValues,
            [],
            'Menghapus customer beserta data terkait.'
        );
    }
}
