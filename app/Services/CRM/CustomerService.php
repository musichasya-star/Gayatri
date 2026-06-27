<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\Request;

class CustomerService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

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
}
