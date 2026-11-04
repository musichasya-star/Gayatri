<?php

namespace App\Services\WhatsApp;

use App\Jobs\SendBirthdayGreetingJob;
use App\Jobs\SendRentalNotificationJob;
use App\Models\User;
use App\Services\CRM\AuditLogService;
use App\Services\CRM\BirthdayGreetingService;
use App\Services\CRM\LaundryNotificationService;
use App\Services\CRM\RentalNotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MonitorRetry
{
    public function session(string $source, $row): string
    {
        return $source === 'message'
            ? ($row->conversation?->whatsappSession?->session_name ?: (string) GatewayConfig::get('default_session'))
            : ($row->session_name ?: (string) GatewayConfig::get('default_session'));
    }

    public function problem(string $source, $row): ?string
    {
        if ($source === 'message') {
            return app(OutboundMessages::class)->retryProblem($row);
        }
        if (in_array($source, ['reminder', 'campaign'], true)) {
            return 'Tinjau jadwal dan konteks melalui modul asal. Monitoring tidak mengulang antrean ini secara langsung.';
        }
        if ($row->sent_at || $row->wa_message_id || $row->message_id) {
            return 'Sudah memiliki bukti penerimaan atau catatan pesan. Kirim ulang dikunci.';
        }
        if ($row->status === 'sending' && ($row->started_at ?? $row->updated_at)->lt(now()->subMinutes(10))) {
            return 'Pengiriman belum selesai lebih dari 10 menit. Periksa worker dan WhatsApp sebelum tindakan lanjutan melalui modul asal.';
        }
        if (! in_array($row->status, ['failed', 'unknown'], true)) {
            return 'Hanya kegagalan atau hasil yang belum pasti yang dapat ditinjau untuk kirim ulang.';
        }
        if ((int) $row->attempts >= 5) {
            return 'Batas lima percobaan tercapai. Tinjau modul asal.';
        }
        if ($row->expires_at?->isPast()) {
            return 'Masa berlaku pesan telah lewat. Tinjau transaksi dan buat pemberitahuan terbaru melalui modul asal.';
        }
        if ($source === 'birthday') {
            $service = app(BirthdayGreetingService::class);
            if (! $service->canDispatch() || $row->birthday_on->toDateString() !== $service->now()->toDateString()) {
                return 'Ucapan hanya dapat dicoba pada tanggal ulang tahun, saat fitur dan jam pengiriman aktif.';
            }
            $recipient = $row->customer ? $service->recipient($row->customer) : null;
            if (! $row->customer || ! $service->occursOn($row->customer, $row->birthday_type, $service->now(), $service->settings())) {
                return 'Data ulang tahun atau status pelanggan sudah berubah.';
            }
        } elseif ($source === 'rental') {
            $service = app(RentalNotificationService::class);
            if (! $service->settings()['enabled'] || ! in_array($row->kind, $service->settings()['events'], true) || ! $service->current($row)) {
                return 'Pengiriman rental nonaktif atau konteks transaksi sudah berubah.';
            }
            $recipient = $service->recipientFor($row->order, $row->kind);
        } else {
            $service = app(LaundryNotificationService::class);
            $settings = $service->settings();
            if (! $settings['enabled'] || $settings['dry_run'] || ! in_array($row->kind, $settings['events'], true) || ! $service->current($row)) {
                return 'Pengiriman laundry nonaktif, dalam simulasi, atau konteks transaksi sudah berubah.';
            }
            $recipient = $service->recipient($row->order, $row->task, true);
        }
        if (! $recipient || ($row->chat_id && $row->chat_id !== $recipient)) {
            return 'Penerima tidak valid atau sudah berubah. Cocokkan kembali melalui modul asal.';
        }

        return null;
    }

    public function retry(string $source, int $id, User $actor, Request $request): void
    {
        abort_unless($actor->status === 'active' && $actor->hasRole(['owner', 'manager', 'admin']), 403);
        abort_unless(in_array($source, ['message', 'birthday', 'rental', 'laundry'], true), 404);
        $data = $request->validate(['fingerprint' => ['required', 'string', 'size:64'], 'reason' => ['required', 'string', 'min:5', 'max:500'], 'confirmed_not_sent' => ['required', 'accepted']]);
        $model = WhatsAppMonitor::MODELS[$source];
        $row = $model::findOrFail($id);
        if ($data['fingerprint'] !== WhatsAppMonitor::fingerprint($source, $row)) {
            throw ValidationException::withMessages(['retry' => 'Catatan berubah. Muat ulang halaman.']);
        }
        if ($problem = $this->problem($source, $row)) {
            throw ValidationException::withMessages(['retry' => $problem]);
        }
        app(GatewayHealth::class)->requireWorking($this->session($source, $row));
        if ($source === 'message') {
            app(OutboundMessages::class)->retry($row, $actor, $request);

            return;
        }
        DB::transaction(function () use ($model, $source, $id, $actor, $request, $data) {
            $row = $model::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($data['fingerprint'] !== WhatsAppMonitor::fingerprint($source, $row)) {
                throw ValidationException::withMessages(['retry' => 'Catatan berubah. Muat ulang halaman.']);
            }
            if ($problem = $this->problem($source, $row)) {
                throw ValidationException::withMessages(['retry' => $problem]);
            }
            $last = DB::table('whatsapp_retry_requests')->where('source', $source)->where('source_id', $id)->latest('id')->first();
            if ($last && Carbon::parse($last->created_at)->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['retry' => 'Tunggu satu menit sebelum mencoba ulang.']);
            }
            $old = $row->status;
            if ($source === 'laundry') {
                app(LaundryNotificationService::class)->retry($row, $actor, $request);
            } else {
                $row->update(['status' => 'queued', 'started_at' => null, 'failure_reason' => null]);
                if ($source === 'rental') {
                    SendRentalNotificationJob::dispatch($row->id)->afterCommit();
                } else {
                    SendBirthdayGreetingJob::dispatch($row->id)->afterCommit();
                }
            }
            DB::table('whatsapp_retry_requests')->insert(['source' => $source, 'source_id' => $id, 'user_id' => $actor->id, 'previous_status' => $old, 'reason' => $data['reason'], 'created_at' => now()]);
            app(AuditLogService::class)->log($actor, 'whatsapp.monitor.retry', $row, $request, ['status' => $old], ['status' => 'queued', 'reason' => $data['reason']], 'Admin memeriksa WhatsApp; konteks dan penerima diperiksa ulang sebelum pengiriman.');
        });
    }
}
