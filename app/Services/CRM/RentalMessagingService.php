<?php

namespace App\Services\CRM;

use App\Models\RentalNotification;
use App\Models\RentalOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class RentalMessagingService
{
    public function __construct(private readonly RentalPortalService $portal, private readonly AuditLogService $audit) {}

    public function verify(RentalOrder $order, string $phone, User $actor, Request $request): void
    {
        $number = preg_replace('/\D/', '', $phone);
        if (str_starts_with($number, '0')) {
            $number = '62'.substr($number, 1);
        }
        if (! preg_match('/^62[1-9]\d{7,13}$/', $number)) {
            throw new InvalidArgumentException('Isi nomor WhatsApp Indonesia yang valid, bukan nomor placeholder.');
        }
        DB::transaction(function () use ($order, $number, $actor, $request) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $changed = $order->notification_phone !== $number;
            $rotate = $order->notification_verified_at && $changed;
            $order->update(['notification_phone' => $number, 'notification_verified_at' => now(), 'notification_verified_by' => $actor->id, 'notification_contact_version' => $order->notification_contact_version + ($changed ? 1 : 0)]);
            if (! $order->portal_token_hash || $rotate || ! $order->portal_expires_at?->isFuture()) {
                $this->portal->issue($order, $request, $actor);
            }
            $this->audit->log($actor, 'rental.messaging.recipient.verify', $order, $request, [], ['phone' => $number, 'contact_version' => $order->notification_contact_version, 'portal_rotated' => (bool) $rotate], 'Tujuan WhatsApp pesanan dicocokkan admin; data CRM pelanggan tetap');
        });
    }

    public function baseUrl(?array $settings = null): ?string
    {
        $base = rtrim((string) (($settings['public_base_url'] ?? '') ?: config('app.url')), '/');
        $parts = parse_url($base);
        if (! $parts || empty($parts['host']) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! empty($parts['path'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (($parts['scheme'] ?? '') !== 'https' || in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || ! str_contains($host, '.')) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $base;
    }

    public function tokenAvailable(RentalOrder $order): bool
    {
        try {
            $token = $order->portal_token_secret;

            return $order->portal_token_hash && $order->portal_expires_at?->isFuture() && is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) && hash_equals($order->portal_token_hash, hash('sha256', $token));
        } catch (Throwable) {
            return false;
        }
    }

    public function link(RentalOrder $order, array $settings, bool $private = false): string
    {
        $base = $this->baseUrl($settings);
        if (! $base || ! $this->tokenAvailable($order)) {
            return 'Hubungi admin untuk tautan portal pesanan.';
        }

        return $base.'/rental/portal/access/'.($private ? $order->portal_token_secret : '[TAUTAN-PRIBADI]');
    }

    public function queuePortal(RentalOrder $order, bool $strict = true): ?RentalNotification
    {
        $order = $order->fresh();
        $service = app(RentalNotificationService::class);
        $settings = $service->settings();
        if (! $strict && (! $settings['enabled'] || ! in_array('portal_shared', $settings['events'], true) || ! $order->notification_verified_at || ! $this->baseUrl($settings) || ! $this->tokenAvailable($order))) {
            return null;
        }
        if (! $settings['enabled'] || ! in_array('portal_shared', $settings['events'], true)) {
            throw new InvalidArgumentException('Aktifkan WhatsApp Rental dan jenis pesan Tautan Portal terlebih dahulu.');
        }
        if (! $order->notification_verified_at || ! $order->notification_phone) {
            throw new InvalidArgumentException('Cocokkan dan simpan tujuan WhatsApp pesanan terlebih dahulu.');
        }
        if (! $this->baseUrl($settings)) {
            throw new InvalidArgumentException('Isi alamat publik HTTPS aplikasi pada pengaturan WhatsApp Rental. Alamat lokal tidak dapat dikirim ke pelanggan.');
        }
        if (! $this->tokenAvailable($order)) {
            throw new InvalidArgumentException('Buat/ganti tautan portal pada pesanan terlebih dahulu. Tautan lama tetap berlaku sampai diganti admin.');
        }

        return $service->enqueue($order, 'portal_shared', 'v'.$order->portal_version.':c'.$order->notification_contact_version.':'.today()->toDateString());
    }

    public static function redact(string $text): string
    {
        foreach (['/rental/portal/access/', '\/rental\/portal\/access\/'] as $prefix) {
            $text = preg_replace_callback('~'.preg_quote($prefix, '~').'[a-f0-9]{64}~', fn () => $prefix.'[TAUTAN-PRIBADI]', $text);
        }

        return $text;
    }

    public static function redactPayload(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::redact($value);
        }
        if (is_array($value)) {
            return array_map(fn ($item) => self::redactPayload($item), $value);
        }

        return $value;
    }
}
