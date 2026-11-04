<?php

namespace App\Services\CRM;

use App\Models\RentalExtensionRequest;
use App\Models\RentalOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class RentalPortalService
{
    public function __construct(private readonly AuditLogService $audit, private readonly RentalExtensionService $extensions) {}

    public function issue(RentalOrder $order, Request $request, ?User $actor = null): string
    {
        return DB::transaction(function () use ($order, $request, $actor) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $token = bin2hex(random_bytes(32));
            $order->update(['portal_token_hash' => hash('sha256', $token), 'portal_token_secret' => $token, 'portal_expires_at' => now()->addDays(90)->max($order->end_date->copy()->addDays(30)), 'portal_version' => $order->portal_version + 1]);
            $this->audit->log($actor, 'rental.portal.issue', $order, $request, [], ['version' => $order->portal_version, 'expires_at' => $order->portal_expires_at->toIso8601String()], 'Tautan portal pesanan diterbitkan');

            return route('rental.portal.access', ['token' => $token]);
        });
    }

    public function grant(Request $request, string $token): RentalOrder
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/D', $token), 404);
        $order = RentalOrder::where('portal_token_hash', hash('sha256', $token))->where('portal_expires_at', '>', now())->firstOrFail();
        $request->session()->regenerate();
        $request->session()->put('rental_portal_access.'.$order->id, $order->portal_version);

        return $order;
    }

    public function authorize(Request $request, string $code): RentalOrder
    {
        $order = RentalOrder::where('code', $code)->firstOrFail();
        abort_unless($order->portal_token_hash && $order->portal_expires_at?->isFuture() && (int) $request->session()->get('rental_portal_access.'.$order->id, -1) === (int) $order->portal_version, 404);

        return $order;
    }

    public function requestExtension(RentalOrder $order, array $data): RentalExtensionRequest
    {
        return DB::transaction(function () use ($order, $data) {
            $order = RentalOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = $order->extensionRequests()->where('entry_key', $data['entry_key'])->first();
            if ($existing) {
                return $existing;
            }
            if ($order->extensionRequests()->where('status', 'pending')->exists()) {
                throw new InvalidArgumentException('Masih ada pengajuan perpanjangan yang menunggu keputusan admin.');
            }
            try {
                $quote = json_decode(Crypt::decryptString($data['extension_token']), true, flags: JSON_THROW_ON_ERROR);
                $current = $this->extensions->preview($order, $data['new_end_date'])['quote'];
                if (($quote['order_id'] ?? null) !== $order->id || ($quote['new_end_date'] ?? '') !== $data['new_end_date'] || ($quote['expires_at'] ?? 0) < now()->timestamp
                    || ($quote['state_hash'] ?? '') !== $current['state_hash'] || ($quote['additional_charge'] ?? -1) !== $current['additional_charge']) {
                    throw new InvalidArgumentException;
                }
            } catch (Throwable) {
                throw new InvalidArgumentException('Periksa ketersediaan dan biaya perpanjangan terbaru sebelum mengajukan.');
            }

            return $order->extensionRequests()->create(['entry_key' => $data['entry_key'], 'new_end_date' => $data['new_end_date'], 'quoted_amount' => $quote['additional_charge'], 'notes' => $data['notes'], 'status' => 'pending']);
        });
    }

    public function reviewExtension(RentalExtensionRequest $entry, string $action, string $notes, User $actor, Request $request): void
    {
        DB::transaction(function () use ($entry, $action, $notes, $actor, $request) {
            $order = RentalOrder::whereKey($entry->rental_order_id)->lockForUpdate()->firstOrFail();
            $entry = $order->extensionRequests()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            if ($entry->status === ($action === 'approve' ? 'approved' : 'rejected')) {
                return;
            }
            if ($entry->status !== 'pending') {
                throw new InvalidArgumentException('Pengajuan telah diproses. Muat ulang halaman.');
            }
            $extensionId = null;
            if ($action === 'approve') {
                $preview = $this->extensions->preview($order, $entry->new_end_date->toDateString());
                if ($preview['quote']['additional_charge'] !== (int) $entry->quoted_amount) {
                    throw new InvalidArgumentException('Biaya berubah dari persetujuan pelanggan. Tolak pengajuan ini dan minta pelanggan mengajukan estimasi terbaru.');
                }
                $extensionId = $this->extensions->apply($order, ['new_end_date' => $entry->new_end_date->toDateString(), 'extension_token' => $preview['token'], 'notes' => $notes], $actor, $request)->id;
            }
            $entry->update(['status' => $action === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_notes' => $notes, 'rental_extension_id' => $extensionId]);
            if ($action === 'reject') {
                app(RentalNotificationService::class)->enqueue($order, 'extension_rejected', 'extension-request:'.$entry->id, ['extension_request_id' => $entry->id]);
            }
            $this->audit->log($actor, 'rental.portal.extension.'.$action, $entry, $request, [], ['status' => $entry->status], 'Pengajuan pelanggan ditinjau');
        });
    }
}
