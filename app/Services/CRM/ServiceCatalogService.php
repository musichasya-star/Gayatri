<?php

namespace App\Services\CRM;

use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;

class ServiceCatalogService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function create(array $data, ?User $actor, Request $request): Service
    {
        $service = Service::create($data);

        $this->auditLogService->log(
            $actor,
            'service.created',
            $service,
            $request,
            [],
            $service->fresh()->toArray(),
            'Membuat data layanan.'
        );

        return $service;
    }

    public function update(Service $service, array $data, ?User $actor, Request $request): Service
    {
        $oldValues = $service->getOriginal();
        $service->update($data);

        $this->auditLogService->log(
            $actor,
            'service.updated',
            $service,
            $request,
            $oldValues,
            $service->fresh()->toArray(),
            'Memperbarui data layanan.'
        );

        return $service;
    }

    public function archive(Service $service, ?User $actor, Request $request): Service
    {
        $oldValues = $service->getOriginal();
        $service->update(['is_active' => false]);

        $this->auditLogService->log(
            $actor,
            'service.archived',
            $service,
            $request,
            $oldValues,
            $service->fresh()->toArray(),
            'Menonaktifkan layanan sebagai arsip.'
        );

        return $service;
    }
}
