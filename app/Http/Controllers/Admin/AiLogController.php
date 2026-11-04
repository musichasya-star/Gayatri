<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Services\AI\AiDecisionRetention;
use App\Services\AI\AiInteractionPolicy;
use App\Services\AI\AiTraceService;
use App\Services\CRM\AuditLogService;
use App\Support\AiDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AiLogController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['domain' => ['nullable', Rule::in(array_keys(AiDomain::LABELS))], 'intent' => 'nullable|string|max:40',
            'reason_code' => 'nullable|string|max:80', 'message_id' => 'nullable|integer|min:1', 'conversation_id' => 'nullable|integer|min:1',
            'delivery' => ['nullable', Rule::in(array_keys(AiTraceService::STATES))], 'mode' => ['nullable', Rule::in(['live', 'simulation', 'operations_demo'])], 'status' => 'nullable|string|max:30']);
        $logs = AiLog::query()
            ->with(['persona', 'knowledgeBase', 'outgoingMessage'])
            ->when($request->filled('domain'), fn ($q) => $q->where(fn ($p) => $p->where('domain', $request->input('domain'))->orWhere(fn ($n) => $n->whereNull('domain')->where('meta->routing->domain', $request->input('domain')))))
            ->when($request->filled('intent'), fn ($q) => $q->where(fn ($p) => $p->where('intent', $request->input('intent'))->orWhere(fn ($n) => $n->whereNull('intent')->where('meta->routing->intent', $request->input('intent')))))
            ->when($request->filled('reason_code'), fn ($q) => $q->where(fn ($p) => $p->where('reason_code', $request->input('reason_code'))->orWhere(fn ($n) => $n->whereNull('reason_code')->where('meta->routing->reason_code', $request->input('reason_code')))))
            ->when($request->filled('message_id'), fn ($q) => $q->where('message_id', $request->integer('message_id')))
            ->when($request->filled('conversation_id'), fn ($q) => $q->where('conversation_id', $request->integer('conversation_id')))
            ->when($request->input('mode') === 'live', fn ($q) => $q->where('meta->mode', 'auto_reply')->where(fn ($p) => $p->whereNull('meta->ops_demo')->orWhere('meta->ops_demo', false)))
            ->when($request->input('mode') === 'simulation', fn ($q) => $q->where(fn ($p) => $p->where('meta->mode', 'simulator')->orWhere('execution_state', 'simulation')))
            ->when($request->input('mode') === 'operations_demo', fn ($q) => $q->where('meta->ops_demo', true))
            ->when($request->filled('delivery'), function ($q) use ($request) {
                $state = $request->input('delivery');
                $q->where(fn ($p) => $p->where(function ($s) use ($state) {
                    $s->whereNull('outgoing_message_id');
                    if ($state === 'simulation') {
                        $s->where(fn ($v) => $v->where('execution_state', 'simulation')->orWhere(fn ($l) => $l->whereNull('execution_state')->where('meta->mode', 'simulator')));
                    } elseif ($state === 'legacy') {
                        $s->whereNull('execution_state')->where(fn ($v) => $v->whereNull('meta->mode')->orWhere('meta->mode', '!=', 'simulator'));
                    } else {
                        $s->where('execution_state', $state);
                    }
                })
                    ->orWhereHas('outgoingMessage', function ($m) use ($state) {
                        if ($state === 'read') {
                            $m->whereNotNull('read_at');
                        } elseif ($state === 'delivered') {
                            $m->whereNotNull('delivered_at')->whereNull('read_at');
                        } elseif ($state === 'unknown') {
                            $m->where(fn ($u) => $u->where('delivery_status', 'unknown')->orWhere(fn ($s) => $s->where('delivery_status', 'sending')->whereRaw('COALESCE(sending_started_at, updated_at) < ?', [now()->subMinutes(10)->toDateTimeString()])))->whereNull('read_at')->whereNull('delivered_at')->whereNull('sent_at')->whereNull('wa_message_id');
                        } elseif ($state === 'sent') {
                            $m->where(fn ($v) => $v->whereNotNull('sent_at')->orWhereNotNull('wa_message_id')->orWhere('delivery_status', 'sent'))->whereNull('read_at')->whereNull('delivered_at');
                        } else {
                            $m->where('delivery_status', $state)->whereNull('read_at')->whereNull('delivered_at')->whereNull('sent_at')->whereNull('wa_message_id');
                        }
                    }));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('admin.ai.logs.index', compact('logs'));
    }

    public function show(AiLog $log): View
    {
        $log->load('outgoingMessage', 'message');

        return view('admin.ai.logs.show', ['log' => $log, 'trace' => app(AiTraceService::class)->describe($log)]);
    }

    public function clear(Request $request): RedirectResponse
    {
        if (app(AiInteractionPolicy::class)->allowsLegacyAutomation()) {
            AiLog::query()->delete();
        } else {
            abort_unless($request->user()->hasRole(['owner', 'manager']), 403);
            $request->validate(['confirmed' => 'required|accepted']);
            $count = app(AiDecisionRetention::class)->prune();
            app(AuditLogService::class)->log($request->user(), 'ai.logs.retention', null, $request, [], ['deleted' => $count], 'Membersihkan log selesai yang berusia lebih dari 90 hari.');
        }

        return redirect()->route('admin.ai.logs.index')->with('status', 'AI logs berhasil dibersihkan.');
    }
}
