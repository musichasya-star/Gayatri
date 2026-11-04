<?php

namespace App\Services\WhatsApp;

use App\Models\AiLog;
use App\Models\BirthdayGreeting;
use App\Models\CampaignRecipient;
use App\Models\LaundryNotification;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\RentalNotification;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppMonitor
{
    public const SOURCES = ['message' => 'Inbox / AI / sistem', 'reminder' => 'Reminder booking', 'campaign' => 'Campaign', 'birthday' => 'Ulang tahun', 'rental' => 'Rental', 'laundry' => 'Laundry'];

    public const STATUSES = ['queued' => 'Dalam antrean', 'sending' => 'Sedang dikirim', 'sent' => 'Diterima gateway', 'failed' => 'Gagal / ditolak', 'unknown' => 'Perlu diperiksa', 'skipped' => 'Dilewati', 'simulated' => 'Simulasi'];

    public const MODELS = ['message' => Message::class, 'reminder' => Reminder::class, 'campaign' => CampaignRecipient::class, 'birthday' => BirthdayGreeting::class, 'rental' => RentalNotification::class, 'laundry' => LaundryNotification::class];

    public static function fingerprint(string $source, $row): string
    {
        return hash('sha256', json_encode([$source, $row->getKey(), $row->updated_at?->format('Y-m-d H:i:s.u'), $row->delivery_status ?? $row->status, $row->delivery_revision ?? null, $row->attempts ?? null, $row->wa_message_id, $row->sent_at?->toDateTimeString()]));
    }

    public function query(Request $request, Carbon $start, Carbon $end): Builder
    {
        $sources = $request->filled('message_id') ? ['message'] : ($request->filled('source') ? [$request->input('source')] : array_keys(self::SOURCES));
        $union = null;
        $staleBefore = now()->subMinutes(10)->toDateTimeString();
        foreach ($sources as $source) {
            $table = match ($source) {
                'message' => 'messages', 'reminder' => 'reminders', 'campaign' => 'campaign_recipients', 'birthday' => 'birthday_greetings', 'rental' => 'rental_notifications', 'laundry' => 'laundry_notifications'
            };
            $q = DB::table($table.' as record')->leftJoin('customers', 'customers.id', '=', 'record.customer_id');
            if (in_array($source, ['rental', 'laundry'], true)) {
                $q = DB::table($table.' as record')->leftJoin($source.'_orders as orders', 'orders.id', '=', 'record.'.$source.'_order_id');
                if ($source === 'laundry') {
                    $q->leftJoin('laundry_transport_tasks as task', 'task.id', '=', 'record.laundry_transport_task_id');
                }
                $q->leftJoin('customers', 'customers.id', '=', DB::raw($source === 'laundry' ? 'COALESCE(orders.customer_id, task.customer_id)' : 'orders.customer_id'));
                if (! $request->boolean('include_demo')) {
                    $q->where(fn ($q) => $q->whereNull('orders.is_demo')->orWhere('orders.is_demo', false));
                }
            }
            if (! $request->boolean('include_demo')) {
                $q->where(fn ($q) => $q->whereNull('customers.name')->orWhere('customers.name', 'not like', '[DEMO]%'));
            }
            if ($request->filled('search')) {
                $search = '%'.$request->input('search').'%';
                $q->where(fn ($q) => $q->where('customers.name', 'like', $search)->orWhere('customers.whatsapp_number', 'like', $search));
            }
            if ($source === 'message') {
                if ($request->filled('message_id')) {
                    $q->where('record.id', $request->integer('message_id'));
                }
                $q->where('record.direction', 'outgoing');
                foreach (['birthday_greetings', 'rental_notifications', 'reminders'] as $origin) {
                    $q->whereNotExists(function ($sub) use ($origin) {
                        $sub->selectRaw('1')->from($origin)->whereColumn($origin.'.message_id', 'record.id');
                    });
                }
                foreach (['birthday_greetings', 'rental_notifications', 'laundry_notifications', 'campaign_recipients', 'reminders'] as $origin) {
                    $q->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from($origin)->whereNotNull('record.wa_message_id')->whereColumn($origin === 'reminders' ? $origin.'.payload->gateway_message_id' : $origin.'.wa_message_id', 'record.wa_message_id'));
                }
                $status = "CASE WHEN record.sent_at IS NOT NULL OR record.delivered_at IS NOT NULL OR record.read_at IS NOT NULL OR record.wa_message_id IS NOT NULL THEN 'sent' WHEN record.delivery_status = 'sending' AND COALESCE(record.sending_started_at, record.updated_at) < ? THEN 'unknown' WHEN record.delivery_status IS NOT NULL THEN record.delivery_status WHEN record.failed_at IS NOT NULL THEN 'unknown' ELSE 'unknown' END";
                $queued = 'record.queued_at';
                $attempts = 'record.send_attempts';
                $reason = 'record.failed_reason';
                $content = 'record.content';
                $kind = 'record.sender_type';
            } else {
                if ($source === 'reminder') {
                    $q->where(fn ($q) => $q->where('record.status', '!=', 'sent')->orWhereNotNull('record.message_id')->orWhereNotNull('record.payload->gateway_message_id'));
                }
                $accepted = $source === 'reminder' ? 'record.sent_at IS NOT NULL' : 'record.sent_at IS NOT NULL OR record.wa_message_id IS NOT NULL';
                $started = in_array($source, ['reminder', 'campaign'], true) ? 'record.updated_at' : 'COALESCE(record.started_at, record.updated_at)';
                $status = "CASE WHEN $accepted THEN 'sent' WHEN record.status IN ('pending','scheduled','queued') THEN 'queued' WHEN record.status IN ('processing','sending') AND $started < ? THEN 'unknown' WHEN record.status IN ('processing','sending') THEN 'sending' WHEN record.status = 'cancelled' THEN 'skipped' WHEN record.status IN ('sent','failed','unknown','skipped','simulated') THEN record.status ELSE 'unknown' END";
                $queued = in_array($source, ['reminder', 'birthday'], true) ? 'record.scheduled_at' : 'record.created_at';
                $attempts = in_array($source, ['reminder', 'campaign'], true) ? '0' : 'record.attempts';
                $reason = in_array($source, ['reminder', 'campaign'], true) ? 'record.failed_reason' : 'record.failure_reason';
                $content = match ($source) {
                    'reminder', 'birthday' => 'record.message', 'campaign' => "''", default => 'record.content'
                };
                $kind = match ($source) {
                    'reminder' => 'record.type', 'birthday' => 'record.birthday_type', 'campaign' => "'campaign'", default => 'record.kind'
                };
            }
            $q->selectRaw('? AS source, record.id AS record_id, customers.id AS customer_id, customers.name AS customer_name, '.$status.' AS delivery_state, record.created_at, record.sent_at, '.$queued.' AS queued_at, COALESCE(record.sent_at, record.created_at) AS event_at, '.$attempts.' AS attempts, '.$reason.' AS failure_reason, '.$content.' AS preview, '.$kind.' AS kind', [$source, $staleBefore]);
            if ($union === null) {
                $union = $q;
            } else {
                $union->unionAll($q);
            }
        }

        return DB::query()->fromSub($union, 'deliveries')->where('event_at', '>=', $start->copy()->startOfDay()->toDateTimeString())->where('event_at', '<', $end->copy()->addDay()->startOfDay()->toDateTimeString())
            ->when($request->filled('status'), fn ($q) => $q->where('delivery_state', $request->input('status')));
    }

    public function decorate($rows): void
    {
        $records = [];
        foreach ($rows->groupBy('source') as $source => $items) {
            $relations = match ($source) {
                'message' => ['customer', 'conversation.whatsappSession'], 'rental' => ['order.customer'], 'laundry' => ['order.customer', 'task.customer'], default => ['customer']
            };
            $records[$source] = self::MODELS[$source]::with($relations)->whereIn('id', $items->pluck('record_id'))->get()->keyBy('id');
        }
        foreach ($rows as $row) {
            $record = $records[$row->source][$row->record_id] ?? null;
            $row->failure_reason = DeliveryFailure::safeText($row->failure_reason);
            $row->preview = DeliveryFailure::safeText($row->preview);
            $row->fingerprint = $record ? self::fingerprint($row->source, $record) : '';
            $row->ai_decision_url = null;
            $row->retry_problem = $record ? app(MonitorRetry::class)->problem($row->source, $record) : 'Catatan tidak tersedia.';
            $row->source_url = match ($row->source) {
                'message' => $record?->conversation_id ? route('admin.inbox', ['conversation' => $record->conversation_id]) : null,
                'reminder' => route('admin.reminders.index'), 'campaign' => route('admin.campaigns.index'),
                'rental' => route('admin.rentals.notifications.index'), 'laundry' => route('admin.laundry.notifications.index'),
                'birthday' => route('admin.birthdays.index'),
            };
        }
        $decisions = AiLog::whereIn('outgoing_message_id', $rows->where('source', 'message')->pluck('record_id'))->pluck('id', 'outgoing_message_id');
        foreach ($rows as $row) {
            if ($row->source === 'message' && isset($decisions[$row->record_id])) {
                $row->ai_decision_url = route('admin.ai.logs.show', $decisions[$row->record_id]);
            }
        }
    }
}
