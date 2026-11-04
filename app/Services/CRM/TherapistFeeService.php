<?php

namespace App\Services\CRM;

use App\Models\Booking;
use App\Models\Therapist;
use App\Models\TherapistFee;
use App\Models\User;
use App\Services\AppSettingService;
use App\Support\ServiceGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TherapistFeeService
{
    public function __construct(private readonly AppSettingService $settings, private readonly AuditLogService $audit) {}

    public function settings(): array
    {
        return array_replace(['enabled' => false, 'start_date' => today()->toDateString(), 'spa_percent' => '0', 'lactation_percent' => '0'], json_decode($this->settings->get('therapist_fee.settings', '{}'), true) ?: []);
    }

    public function saveSettings(array $data, User $actor, Request $request): void
    {
        DB::transaction(function () use ($data, $actor, $request) {
            $before = $this->settings();
            $this->settings->set('therapist_fee.settings', json_encode($data));
            $this->audit->log($actor, 'therapist_fee.settings', null, $request, $before, $data, 'Mengubah kebijakan fee terapis untuk pencatatan berikutnya.');
        });
    }

    public function cents(string|int|float|null $amount): int
    {
        $text = (string) ($amount ?? '0');
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $text, $matches)) {
            throw ValidationException::withMessages(['fee' => 'Nominal/persentase harus berupa angka maksimal dua desimal.']);
        }

        return ($matches[1] === '-' ? -1 : 1) * ((int) $matches[2] * 100 + (int) str_pad($matches[3] ?? '', 2, '0'));
    }

    public function eligible(Booking $booking): bool
    {
        return $booking->status === 'completed' && $booking->payment_status === 'paid'
            && $booking->therapist_id && $booking->service_id && $booking->booking_date->lte(today());
    }

    public function figures(Booking $booking, ?int $fixedRate = null): array
    {
        $booking->loadMissing('service', 'therapist', 'addOns', 'customer');
        $group = $booking->service?->service_group ?? ServiceGroup::SPA;
        $settings = $this->settings();
        $override = $booking->therapist?->getAttribute('fee_'.$group.'_percent');
        $rate = $fixedRate ?? $this->cents($override ?? $settings[$group.'_percent']);
        $base = $this->cents($booking->service_price_snapshot ?? $booking->service?->price);
        $addons = $booking->addOns->sum(fn ($addon) => $this->cents($addon->price));
        $discount = $this->cents($booking->promo_discount);
        $net = max(0, $base + $addons - $discount);
        $financial = ['therapist_id' => $booking->therapist_id, 'service_id' => $booking->service_id, 'service_group' => $group,
            'service_date' => $booking->booking_date->toDateString(), 'branch_id' => $booking->branch_id,
            'main_cents' => $base, 'addons_cents' => $addons, 'discount_cents' => $discount];

        return ['base_cents' => $net, 'rate_bps' => $rate, 'fee_cents' => intdiv($net * $rate + 500000, 1000000) * 100,
            'signature' => hash('sha256', json_encode($financial)), 'snapshot' => $financial + ['travel_excluded_cents' => $this->cents($booking->travel_fee),
                'price_source' => $booking->service_price_snapshot === null ? 'legacy_catalog_confirmed' : 'booking_snapshot',
                'rate_source' => $fixedRate !== null ? 'frozen' : ($override !== null ? 'therapist' : 'default'),
                'addons' => $booking->addOns->map(fn ($addon) => ['name' => $addon->name, 'cents' => $this->cents($addon->price)])->all()]];
    }

    public function sync(Booking $booking, ?User $actor = null, ?Request $request = null, bool $historical = false): ?TherapistFee
    {
        return DB::transaction(function () use ($booking, $actor, $request, $historical) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $fee = TherapistFee::where('booking_id', $booking->id)->lockForUpdate()->first();
            $request ??= Request::create('/internal/therapist-fee');
            if (! $this->eligible($booking)) {
                if ($fee && $fee->status !== 'void') {
                    $before = $fee->toArray();
                    $fee->update(['status' => 'void', 'void_reason' => 'Booking tidak lagi selesai, lunas, bertanggal pelayanan sampai hari ini, dan memiliki terapis serta layanan.']);
                    $this->audit->log($actor, 'therapist_fee.void', $fee, $request, $before, $fee->fresh()->toArray(), 'Fee dikeluarkan dari total laporan.');
                }

                return $fee;
            }
            $settings = $this->settings();
            if (! $fee && (! $settings['enabled'] || $booking->booking_date->toDateString() < $settings['start_date'])) {
                return null;
            }
            // Legacy completed bookings require the explicit historical preview, never a silent catalogue fallback.
            if (! $fee && $booking->service_price_snapshot === null && ! $historical) {
                return null;
            }
            $therapist = Therapist::whereKey($booking->therapist_id)->lockForUpdate()->firstOrFail();
            $booking->load('service', 'addOns', 'customer')->setRelation('therapist', $therapist);
            $samePolicy = $fee && $fee->therapist_id === $booking->therapist_id && $fee->service_group === $booking->service->service_group;
            $figures = $this->figures($booking, $samePolicy ? $fee->rate_bps : null);
            if ($fee && $fee->signature === $figures['signature'] && $fee->status === 'accrued') {
                return $fee;
            }
            $before = $fee?->toArray() ?? [];
            if ($booking->service_price_snapshot === null) {
                $booking->forceFill(['service_price_snapshot' => number_format($figures['snapshot']['main_cents'] / 100, 2, '.', '')])->saveQuietly();
            }
            $values = $figures + ['booking_id' => $booking->id, 'booking_code' => $booking->booking_code, 'therapist_id' => $booking->therapist_id,
                'therapist_name' => $booking->therapist->name, 'service_name' => $booking->service->name, 'service_group' => $booking->service->service_group,
                'branch_id' => $booking->branch_id, 'service_date' => $booking->booking_date->toDateString(), 'status' => 'accrued', 'void_reason' => null,
                'recorded_by' => $actor?->id, 'is_demo' => str_starts_with((string) $booking->notes, '[DEMO]') || str_starts_with((string) $booking->customer?->name, '[DEMO]')];
            if ($fee) {
                $fee->update($values);
            } else {
                $fee = TherapistFee::create($values);
            }
            $this->audit->log($actor, $before ? 'therapist_fee.corrected' : 'therapist_fee.accrued', $fee, $request, $before, $fee->fresh()->toArray(), $historical ? 'Pencatatan dari pratinjau histori yang disetujui admin.' : 'Fee berdasarkan booking selesai dan lunas.');

            return $fee;
        });
    }

    public function candidates(array $filters)
    {
        return Booking::with(['service', 'therapist', 'addOns', 'customer'])->where('status', 'completed')->where('payment_status', 'paid')
            ->whereNotNull('therapist_id')->whereHas('service')->whereDoesntHave('therapistFee')
            ->whereDate('booking_date', '>=', max($filters['start_date'], $this->settings()['start_date']))->whereDate('booking_date', '<=', $filters['end_date'])
            ->when($filters['therapist_id'] ?? null, fn ($q, $id) => $q->where('therapist_id', $id))
            ->when($filters['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['service_group'] ?? null, fn ($q, $group) => $q->whereHas('service', fn ($s) => $s->where('service_group', $group)))
            ->when(! ($filters['include_demo'] ?? false), fn ($q) => $q->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', '[DEMO]%'))->whereHas('customer', fn ($c) => $c->where('name', 'not like', '[DEMO]%')));
    }

    public function preview(array $filters): array
    {
        $rows = $this->candidates($filters)->orderBy('id')->limit(100)->get()->map(fn ($booking) => ['booking' => $booking, 'figures' => $this->figures($booking)]);
        $payload = ['expires' => now()->addMinutes(15)->timestamp, 'settings' => $this->settings(), 'rows' => $rows->map(fn ($row) => ['id' => $row['booking']->id, 'signature' => $row['figures']['signature'], 'rate_bps' => $row['figures']['rate_bps']])->all()];

        return ['rows' => $rows, 'token' => Crypt::encryptString(json_encode($payload))];
    }

    public function process(string $token, User $actor, Request $request): int
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['fee' => 'Pratinjau tidak valid. Buat pratinjau baru.']);
        }
        if (($payload['expires'] ?? 0) < now()->timestamp || ($payload['settings'] ?? null) !== $this->settings() || ! $this->settings()['enabled']) {
            throw ValidationException::withMessages(['fee' => 'Pratinjau kedaluwarsa atau kebijakan berubah. Aktifkan fee dan buat pratinjau baru.']);
        }

        return DB::transaction(function () use ($payload, $actor, $request) {
            $count = 0;
            foreach ($payload['rows'] as $row) {
                $booking = Booking::whereKey($row['id'])->lockForUpdate()->first();
                if (! $booking || ! $this->eligible($booking)) {
                    throw ValidationException::withMessages(['fee' => 'Status booking berubah. Buat pratinjau baru.']);
                }
                if ($booking->therapistFee()->exists()) {
                    continue;
                }
                $booking->setRelation('therapist', Therapist::whereKey($booking->therapist_id)->lockForUpdate()->firstOrFail());
                $figures = $this->figures($booking);
                if ($figures['signature'] !== $row['signature'] || $figures['rate_bps'] !== $row['rate_bps']) {
                    throw ValidationException::withMessages(['fee' => 'Harga, penugasan, atau persentase berubah. Buat pratinjau baru.']);
                }
                if ($this->sync($booking, $actor, $request, true)) {
                    $count++;
                }
            }

            return $count;
        });
    }
}
