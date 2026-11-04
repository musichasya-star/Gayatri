<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppSession;
use App\Services\WhatsApp\DeliveryFailure;
use App\Services\WhatsApp\GatewayConfig;
use App\Services\WhatsApp\GatewayHealth;
use App\Services\WhatsApp\MonitorRetry;
use App\Services\WhatsApp\QueueMonitor;
use App\Services\WhatsApp\WhatsAppMonitor;
use App\Support\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WhatsAppMonitorController extends Controller
{
    public function index(Request $request, WhatsAppMonitor $monitor, GatewayHealth $health, QueueMonitor $queue)
    {
        $request->validate(['message_id' => 'nullable|integer|min:1']);
        $request->validate(['source' => ['nullable', Rule::in(array_keys(WhatsAppMonitor::SOURCES))], 'status' => ['nullable', Rule::in(array_keys(WhatsAppMonitor::STATUSES))], 'search' => ['nullable', 'string', 'max:100'], 'include_demo' => ['nullable', 'boolean']]);
        [$start, $end] = ReportPeriod::dates($request, today()->subDays(6), today());
        $query = $monitor->query($request, $start, $end);
        $counts = (clone $query)->select('delivery_state')->selectRaw('COUNT(*) AS total')->groupBy('delivery_state')->pluck('total', 'delivery_state');
        $rows = $query->orderByDesc('event_at')->orderBy('source')->orderByDesc('record_id')->paginate(20)->withQueryString();
        $monitor->decorate($rows->getCollection());
        $retries = DB::table('whatsapp_retry_requests')->leftJoin('users', 'users.id', '=', 'whatsapp_retry_requests.user_id')->select('whatsapp_retry_requests.*', 'users.name as actor')->latest('whatsapp_retry_requests.id')->limit(5)->get();
        foreach ($retries as $retry) {
            $retry->reason = DeliveryFailure::safeText($retry->reason);
        }

        return view('admin.whatsapp.monitor', ['rows' => $rows, 'counts' => $counts, 'sources' => WhatsAppMonitor::SOURCES, 'statuses' => WhatsAppMonitor::STATUSES, 'health' => $health->inspect(), 'queue' => $queue->inspect(), 'localSessions' => WhatsAppSession::select('session_name', 'status', 'last_seen_at')->get(), 'defaultSession' => (string) GatewayConfig::get('default_session'), 'startDate' => $start->toDateString(), 'endDate' => $end->toDateString(), 'retries' => $retries]);
    }

    public function refresh(GatewayHealth $health)
    {
        $health->inspect(true);

        return back()->with('status', 'Status gateway diperiksa kembali. Tidak ada sesi yang dibuat, dijalankan, atau diubah.');
    }

    public function retry(Request $request, string $source, int $id, MonitorRetry $service)
    {
        $service->retry($source, $id, $request->user(), $request);

        return back()->with('status', 'Satu percobaan ulang masuk antrean. Penerima dan status diperiksa lagi oleh worker.');
    }
}
