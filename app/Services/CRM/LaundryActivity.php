<?php

namespace App\Services\CRM;

use App\Models\LaundryOrder;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LaundryActivity
{
    public function __construct(private readonly AuditLogService $audit) {}

    public static function authorize(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasRole(['owner', 'manager', 'admin'])) {
            throw new AuthorizationException('Akses laundry hanya untuk admin aktif.');
        }
    }

    public function lock(LaundryOrder $order, int $revision): LaundryOrder
    {
        $order = LaundryOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
        if ($order->status === 'cancelled' || $order->revision !== $revision) {
            throw ValidationException::withMessages(['laundry' => 'Pesanan dibatalkan atau data berubah. Muat ulang halaman sebelum melanjutkan.']);
        }

        return $order;
    }

    public function record(LaundryOrder $order, string $kind, User $actor, array $details, Request $request): void
    {
        $order->increment('revision');
        $order->events()->create(['kind' => $kind, 'revision' => $order->revision, 'actor_id' => $actor->id, 'details' => $details, 'created_at' => now()]);
        $this->audit->log($actor, 'laundry.order.'.$kind, $order, $request, [], ['revision' => $order->revision, 'workflow_status' => $order->workflow_status, 'details' => $details], 'Operasional/pembayaran laundry diperbarui.');
        app(LaundryNotificationService::class)->captureOrder($order, $kind, $details);
    }
}
