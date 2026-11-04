<?php

namespace App\Services\CRM;

use App\Models\Service;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCatalogService
{
    public function __construct(private readonly AuditLogService $auditLogService) {}

    public function create(array $data, ?User $actor, Request $request): Service
    {
        $addons = $data['addons'] ?? [];
        $serviceData = $this->serviceData($data);
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
        $syncAddons = array_key_exists('addons', $data) || ! empty($data['addons_present']);
        if (($data['booking_mode'] ?? $service->booking_mode) === 'online'
            && ((int) data_get($service->catalog_metadata, 'package_quantity', 1) > 1
                || in_array(data_get($service->catalog_metadata, 'role'), ['addon', 'bundle'], true))) {
            throw ValidationException::withMessages(['booking_mode' => 'Paket beberapa sesi/kunjungan, gabungan layanan, dan tambahan tetap dikonfirmasi admin. Jangan diubah menjadi satu booking biasa.']);
        }
        $serviceData = $this->serviceData($data, $service);

        DB::transaction(function () use ($service, $serviceData, $addons, $syncAddons) {
            $service->update($serviceData);
            if ($syncAddons) {
                $this->syncAddOns($service, $addons);
            }
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
        $seenIds = [];
        $seenServices = [];

        foreach ($addons as $addon) {
            $addonServiceId = (int) ($addon['addon_service_id'] ?? 0);
            $addonName = trim((string) ($addon['addon_name'] ?? ''));
            if ($addonServiceId <= 0 && $addonName === '') {
                continue;
            }
            $id = (int) ($addon['id'] ?? 0);
            if (($id && isset($seenIds[$id])) || ($addonServiceId && isset($seenServices[$addonServiceId]))) {
                throw ValidationException::withMessages(['addons' => 'Tambahan yang sama tercantum lebih dari sekali. Gunakan satu baris per tambahan.']);
            }
            if ($id) {
                $seenIds[$id] = true;
            }
            if ($addonServiceId) {
                $seenServices[$addonServiceId] = true;
            }
            if ($addonServiceId === $service->id) {
                throw ValidationException::withMessages(['addons' => 'Layanan tidak dapat menjadi tambahannya sendiri.']);
            }
            if ($addonServiceId > 0 && ! Service::whereKey($addonServiceId)->where('service_group', $service->service_group)->exists()) {
                throw ValidationException::withMessages(['addons' => 'Layanan tambahan harus berada dalam kelompok layanan yang sama.']);
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
            if (! empty($addon['id']) && ! $model) {
                throw ValidationException::withMessages(['addons' => 'Tambahan ini bukan milik layanan yang diedit. Muat ulang formulir.']);
            }
            $model ??= $addonServiceId > 0
                ? $service->addOns()->where('addon_service_id', $addonServiceId)->first()
                : null;
            $payload['requires_confirmation'] = (bool) ($addon['requires_confirmation'] ?? $model?->requires_confirmation ?? false);

            if ($model) {
                $model->update($payload);
            } else {
                $model = $service->addOns()->create($payload);
            }

            $keepIds[] = $model->id;
        }

        $service->addOns()
            ->when($keepIds !== [], fn ($query) => $query->whereNotIn('id', $keepIds))
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function serviceData(array $data, ?Service $service = null): array
    {
        $attributes = Arr::except($data, ['addons', 'photo', 'addons_present', 'catalog_kind', 'package_quantity', 'package_unit']);
        if (array_key_exists('catalog_kind', $data) || array_key_exists('package_quantity', $data)) {
            $kind = $data['catalog_kind'] ?? $service?->catalogueKind() ?? 'treatment';
            if ($service && in_array($service->catalogueKind(), ['package', 'addon'], true) && $kind !== $service->catalogueKind()) {
                throw ValidationException::withMessages(['catalog_kind' => 'Paket dan tambahan yang sudah ada tidak dapat diubah menjadi treatment tunggal. Buat variasi terpisah bila diperlukan.']);
            }
            $metadata = array_replace($service?->catalog_metadata ?? [], ['kind' => $kind,
                'package_quantity' => (int) ($data['package_quantity'] ?? data_get($service?->catalog_metadata, 'package_quantity', 1)),
                'package_unit' => $data['package_unit'] ?? data_get($service?->catalog_metadata, 'package_unit', 'sesi')]);
            if ($metadata['package_quantity'] > 1 && $kind !== 'package') {
                throw ValidationException::withMessages(['catalog_kind' => 'Pilihan beberapa sesi/hari harus memakai jenis Paket.']);
            }
            $attributes['catalog_metadata'] = $metadata;
        }
        $candidate = clone ($service ?? new Service);
        $candidate->fill($attributes);
        if (($attributes['booking_mode'] ?? $service?->booking_mode ?? 'online') === 'online' && in_array($candidate->catalogueKind(), ['package', 'addon'], true)) {
            throw ValidationException::withMessages(['booking_mode' => 'Paket dan tambahan tetap melalui konfirmasi admin.']);
        }
        if (($data['photo'] ?? null) instanceof UploadedFile) {
            $attributes['image_path'] = $data['photo']->store('services/catalog', 'public');
        }

        return $attributes;
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
