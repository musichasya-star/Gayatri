<?php

namespace App\Services\WhatsApp;

use App\Jobs\GenerateAiReplyJob;
use App\Jobs\ProcessCampaignRecipientJob;
use App\Jobs\ProcessIncomingWhatsAppMessageJob;
use App\Jobs\SendBirthdayGreetingJob;
use App\Jobs\SendBookingReminderJob;
use App\Jobs\SendHomeVisitAssignmentJob;
use App\Jobs\SendLaundryNotificationJob;
use App\Jobs\SendRentalNotificationJob;
use App\Jobs\SendWhatsAppMessageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueMonitor
{
    public const JOBS = [
        SendWhatsAppMessageJob::class => 'Kirim pesan inbox / AI', SendBookingReminderJob::class => 'Reminder booking',
        ProcessCampaignRecipientJob::class => 'Campaign', SendBirthdayGreetingJob::class => 'Ucapan ulang tahun',
        SendRentalNotificationJob::class => 'Notifikasi rental', SendLaundryNotificationJob::class => 'Notifikasi laundry',
        SendHomeVisitAssignmentJob::class => 'Penugasan home service', GenerateAiReplyJob::class => 'Susun balasan AI',
        ProcessIncomingWhatsAppMessageJob::class => 'Proses pesan masuk',
    ];

    public static function heartbeat(string $connection, ?string $queues): void
    {
        try {
            foreach (explode(',', $queues ?: (string) config('queue.connections.'.$connection.'.queue', 'default')) as $queue) {
                $key = hash('sha256', $connection.'|'.trim($queue));
                if (Cache::add('whatsapp:worker-throttle:'.$key, true, 15)) {
                    Cache::put('whatsapp:worker:'.$key, now()->toDateTimeString(), 180);
                }
            }
        } catch (Throwable) {
            // Monitoring must never interrupt a queue worker when its cache is unavailable.
        }
    }

    public function inspect(): array
    {
        $connection = (string) config('queue.default');
        $config = config('queue.connections.'.$connection, []);
        $driver = $config['driver'] ?? 'unknown';
        $result = ['driver' => $driver, 'available' => false, 'error' => null, 'pending' => null, 'processing' => null, 'failed' => null, 'jobs' => collect(), 'failures' => collect(), 'workers' => []];
        foreach (array_unique([(string) ($config['queue'] ?? 'default'), 'default', 'rental', 'laundry']) as $queue) {
            try {
                $seen = Cache::get('whatsapp:worker:'.hash('sha256', $connection.'|'.$queue));
            } catch (Throwable) {
                $seen = null;
            }
            $result['workers'][] = ['queue' => $queue, 'seen_at' => $seen];
        }
        if ($driver === 'sync') {
            $result['error'] = 'Mode langsung: pekerjaan dijalankan pada proses pemanggil dan tidak memiliki antrean tertunda.';

            return $result;
        }
        if ($driver !== 'database') {
            $result['error'] = 'Rincian antrean tersedia untuk driver database. Catatan pengiriman tetap ditampilkan di bawah.';

            return $result;
        }
        try {
            $db = DB::connection($config['connection'] ?? null);
            $query = $db->table($config['table'] ?? 'jobs')->whereIn('payload->displayName', array_keys(self::JOBS));
            $result['pending'] = (clone $query)->whereNull('reserved_at')->count();
            $result['processing'] = (clone $query)->whereNotNull('reserved_at')->count();
            $result['jobs'] = $query->select('id', 'queue', 'attempts', 'reserved_at', 'available_at', 'created_at', 'payload->displayName as job_name')->orderBy('created_at')->limit(30)->get()->map(function ($row) {
                $row->label = self::JOBS[$row->job_name] ?? 'Pekerjaan WhatsApp';
                $row->state = $row->reserved_at ? 'Sedang diproses' : ($row->available_at > now()->timestamp ? 'Menunggu jadwal' : 'Menunggu worker');

                return $row;
            });
            $failed = config('queue.failed', []);
            if (in_array($failed['driver'] ?? '', ['database', 'database-uuids'], true)) {
                $failures = DB::connection($failed['database'] ?? null)->table($failed['table'] ?? 'failed_jobs')->where('connection', $connection)->whereIn('payload->displayName', array_keys(self::JOBS));
                $result['failed'] = (clone $failures)->count();
                $result['failures'] = $failures->select('id', 'queue', 'failed_at', 'payload->displayName as job_name')->orderByDesc('id')->limit(10)->get()->map(function ($row) {
                    $row->label = self::JOBS[$row->job_name] ?? 'Pekerjaan WhatsApp';

                    return $row;
                });
            }
            $result['available'] = true;
        } catch (Throwable) {
            $result['error'] = 'Penyimpanan antrean belum dapat dibaca. Periksa koneksi database antrean.';
        }

        return $result;
    }
}
