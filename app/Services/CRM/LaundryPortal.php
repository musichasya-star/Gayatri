<?php

namespace App\Services\CRM;

use App\Models\LaundryOrder;
use App\Models\LaundryPublicRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LaundryPortal
{
    public function __construct(private readonly LaundryPublicSettings $settings, private readonly AuditLogService $audit) {}

    public function issue(LaundryPublicRequest $entry, Request $request, ?User $actor = null): void
    {
        if ($actor) {
            LaundryActivity::authorize($actor);
        }
        DB::transaction(function () use ($entry, $request, $actor) {
            $entry = LaundryPublicRequest::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $token = Str::random(64);
            $entry->update(['portal_token_hash' => hash('sha256', $token), 'portal_token_secret' => $token, 'portal_expires_at' => now()->addDays($this->settings->get()['portal_days']), 'portal_revoked_at' => null, 'portal_version' => $entry->portal_version + 1]);
            $this->audit->log($actor, 'laundry.portal.issue', $entry, $request, [], ['version' => $entry->portal_version, 'expires_at' => $entry->portal_expires_at->toIso8601String()], 'Akses portal pribadi laundry diterbitkan.');
        });
    }

    public function revoke(LaundryPublicRequest $entry, User $actor, Request $request): void
    {
        LaundryActivity::authorize($actor);
        DB::transaction(function () use ($entry, $actor, $request) {
            $entry = LaundryPublicRequest::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $entry->update(['portal_revoked_at' => now(), 'portal_token_hash' => null, 'portal_token_secret' => null, 'portal_version' => $entry->portal_version + 1]);
            $this->audit->log($actor, 'laundry.portal.revoke', $entry, $request, [], ['version' => $entry->portal_version], 'Akses portal laundry dicabut.');
        });
    }

    public function link(LaundryPublicRequest $entry): ?string
    {
        if (! $entry->portal_token_hash || $entry->portal_revoked_at || ! $entry->portal_expires_at->isFuture()) {
            return null;
        }
        $base = rtrim($this->settings->get()['portal_base_url'] ?: config('app.url'), '/');

        return $base.'/laundry/akses#'.$entry->portal_token_secret;
    }

    public static function redact(string $text): string
    {
        return preg_replace('~(/laundry/akses#)[A-Za-z0-9]{64}~', '$1[tautan-pribadi]', $text);
    }

    public function grant(Request $request, LaundryPublicRequest $entry): void
    {
        abort_unless($entry->portal_token_hash && ! $entry->portal_revoked_at && $entry->portal_expires_at->isFuture(), 404);
        $request->session()->regenerate();
        $request->session()->put('laundry.portal.'.$entry->public_id, ['version' => $entry->portal_version, 'until' => now()->addHours(2)->timestamp]);
    }

    public function access(string $token, Request $request): LaundryPublicRequest
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/D', $token), 404);
        $entry = LaundryPublicRequest::where('portal_token_hash', hash('sha256', $token))->whereNull('portal_revoked_at')->where('portal_expires_at', '>', now())->firstOrFail();
        $this->grant($request, $entry);

        return $entry;
    }

    public function authorize(string $publicId, Request $request): LaundryPublicRequest
    {
        $entry = LaundryPublicRequest::where('public_id', $publicId)->firstOrFail();
        $grant = $request->session()->get('laundry.portal.'.$publicId, []);
        abort_unless($entry->portal_token_hash && ! $entry->portal_revoked_at && $entry->portal_expires_at->isFuture() && (int) ($grant['version'] ?? -1) === $entry->portal_version && ($grant['until'] ?? 0) > now()->timestamp, 404);

        return $entry;
    }

    public static function priceReady(LaundryOrder $order): bool
    {
        $entry = $order->publicRequest()->first();
        if (! $entry) {
            return true;
        }
        if (! $entry->verified_at || $entry->status !== 'received') {
            return false;
        }
        foreach (['pickup' => $entry->fulfilment === 'pickup', 'delivery' => $entry->delivery_required] as $kind => $needed) {
            if ($needed && ! array_key_exists($kind, $order->transport_pricing ?? [])) {
                return false;
            }
        }

        return true;
    }

    public static function requireApprovedPrice(LaundryOrder $order): void
    {
        if ($order->publicRequest()->exists() && (! self::priceReady($order) || $order->approval_fingerprint !== $order->pricingFingerprint())) {
            throw ValidationException::withMessages(['laundry' => 'Konfirmasikan ongkir yang diminta dan minta persetujuan atas rincian tagihan terbaru sebelum melanjutkan.']);
        }
    }

    public function approve(LaundryPublicRequest $entry, array $data, Request $request): void
    {
        abort_unless($entry->laundry_order_id && $entry->verified_at, 404);
        DB::transaction(function () use ($entry, $data, $request) {
            $order = LaundryOrder::whereKey($entry->laundry_order_id)->lockForUpdate()->firstOrFail();
            $current = $order->pricingFingerprint();
            if ($order->status === 'approved' && $order->approval_fingerprint === $current && hash_equals($current, $data['fingerprint'])) {
                return;
            }
            if ($order->status !== 'awaiting_approval' || $order->actual_total === null || $order->revision !== (int) $data['revision'] || ! self::priceReady($order) || ! hash_equals($current, $data['fingerprint'])) {
                throw ValidationException::withMessages(['approval' => 'Rincian berubah atau belum lengkap. Muat ulang dan periksa hasil timbang serta ongkir terbaru.']);
            }
            $order->update(['status' => 'approved', 'approved_at' => now(), 'approved_name' => $data['approved_name'], 'approval_channel' => 'portal', 'approval_reference' => 'Persetujuan pelanggan melalui portal pribadi laundry.', 'approval_fingerprint' => $current, 'revision' => $order->revision + 1]);
            $details = ['amount' => $order->totalDue(), 'approved_name' => $data['approved_name'], 'reference' => $order->approval_reference, 'channel' => 'portal', 'fingerprint' => $current, 'service_total' => $order->actual_total, 'pickup_fee' => $order->pickup_fee, 'delivery_fee' => $order->delivery_fee, 'items' => $order->items()->get()->toArray()];
            $order->events()->create(['actor_id' => null, 'kind' => 'approved', 'revision' => $order->revision, 'details' => $details, 'created_at' => now()]);
            $this->audit->log(null, 'laundry.portal.approve', $order, $request, [], ['fingerprint' => $current, 'total' => $order->totalDue(), 'source' => 'customer_portal'], 'Pelanggan menyetujui hasil timbang dan total tagihan melalui portal.');
        });
    }
}
