<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Models\Therapist;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendHomeVisitAssignmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $bookingId, public readonly string $revision, public readonly ?int $previousTherapistId = null, public readonly bool $withdraw = false) {}

    public function handle(WhatsAppGateway $gateway): void
    {
        $booking = Booking::with(['customer', 'service', 'therapist'])->find($this->bookingId);
        if ($this->withdraw) {
            if (! $booking || ($booking->service_mode === 'home' && $booking->status === 'confirmed' && (int) $booking->therapist_id === $this->previousTherapistId)) {
                return;
            }
            $staff = Therapist::find($this->previousTherapistId);
        } else {
            $staff = $booking?->therapist;
            if ($booking && $booking->homeVisitRevision() !== $this->revision) {
                return;
            }
        }
        if (! $booking || (! $this->withdraw && ($booking->service_mode !== 'home' || ! in_array($booking->status, ['confirmed', 'cancelled', 'cancelled_by_user'], true))) || blank($staff?->phone)) {
            return;
        }
        $phone = preg_replace('/\D/', '', $staff->phone);
        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }
        $key = 'home-assignment:'.$booking->id.':'.$staff->id.':'.$this->revision.':'.(int) $this->withdraw;
        if (Cache::has($key)) {
            return;
        }
        if ($this->withdraw) {
            $gateway->sendText(GatewayConfig::get('default_session'), $phone.'@c.us', 'Penugasan Home Service '.$booking->booking_code.' dibatalkan atau dialihkan kepada terapis lain. Abaikan jadwal dan lokasi penugasan sebelumnya; hubungi admin jika perlu konfirmasi.');
        } else {
            $gateway->sendText(GatewayConfig::get('default_session'), $phone.'@c.us',
                ($booking->status === 'confirmed' ? 'Penugasan' : 'DIBATALKAN — Jangan berangkat untuk').' Home Service '.$booking->booking_code.' — '.$booking->service?->name."\nPelanggan: ".$booking->customer?->name.' ('.$booking->customer?->whatsapp_number.')'."\nJadwal: ".$booking->booking_date->format('d M Y').' '.substr($booking->start_time, 0, 5)."\nAlamat: ".$booking->visit_address."\nPatokan: ".($booking->visit_landmark ?: '-')."\nCadangan waktu: ".$booking->busy_from?->format('H:i').'–'.$booking->busy_until?->format('H:i')."\nNavigasi: ".$booking->navigationUrl());
        }
        Cache::put($key, true, now()->addDays(30));
    }
}
