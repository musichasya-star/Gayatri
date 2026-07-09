<?php

namespace App\Services\CRM;

use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ServiceCatalogService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function create(array $data, ?User $actor, Request $request): Service
    {
        $addons = $data['addons'] ?? [];
        $serviceData = Arr::except($data, ['addons']);
        $service = DB::transaction(function () use ($serviceData, $addons) {
            $service = Service::create($serviceData);
            $this->syncAddOns($service, $addons);

            return $service;
        });

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
        $addons = $data['addons'] ?? [];
        $serviceData = Arr::except($data, ['addons']);

        DB::transaction(function () use ($service, $serviceData, $addons) {
            $service->update($serviceData);
            $this->syncAddOns($service, $addons);
        });

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

    private function syncAddOns(Service $service, array $addons): void
    {
        $keepIds = [];

        foreach ($addons as $addon) {
            $addonServiceId = (int) ($addon['addon_service_id'] ?? 0);
            $addonName = trim((string) ($addon['addon_name'] ?? ''));
            if (($addonServiceId <= 0 && $addonName === '') || $addonServiceId === $service->id) {
                continue;
            }

            $payload = [
                'addon_service_id' => $addonServiceId > 0 ? $addonServiceId : null,
                'addon_name' => $addonServiceId > 0 ? null : $addonName,
                'duration_minutes' => (int) ($addon['duration_minutes'] ?? 0),
                'price_adjustment' => (float) ($addon['price_adjustment'] ?? 0),
                'is_active' => (bool) ($addon['is_active'] ?? true),
            ];

            $model = ! empty($addon['id'])
                ? $service->addOns()->whereKey($addon['id'])->first()
                : null;
            $model ??= $addonServiceId > 0
                ? $service->addOns()->where('addon_service_id', $addonServiceId)->first()
                : null;

            if ($model) {
                $model->update($payload);
            } else {
                $model = $service->addOns()->create($payload);
            }

            $keepIds[] = $model->id;
        }

        $service->addOns()
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->delete();
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
