<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\User;
use App\Services\CRM\RetentionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FollowupController extends Controller
{
    public function index(Request $request): View
    {
        $followups = Followup::query()
            ->with(['customer', 'assignedUser', 'conversation'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $keyword = '%'.$request->string('q')->toString().'%';
                $query->where(function ($inner) use ($keyword) {
                    $inner->where('title', 'like', $keyword)
                        ->orWhere('notes', 'like', $keyword)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $keyword)->orWhere('whatsapp_number', 'like', $keyword));
                });
            })
            ->orderByRaw('completed_at is not null')
            ->orderBy('due_at')
            ->paginate(15)
            ->withQueryString();

        return view('admin.followups.index', compact('followups'));
    }

    public function create(Request $request): View
    {
        $followup = new Followup([
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => now()->addDay(),
        ]);

        $followup->fill($this->defaultsFromQuery($request));

        return view('admin.followups.form', $this->formData($followup));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedData($request);
        $data['assigned_user_id'] ??= $request->user()->id;

        $followup = Followup::create($data);

        return redirect()->route('admin.followups.show', $followup)->with('status', 'Follow-up berhasil dibuat.');
    }

    public function show(Followup $followup): View
    {
        $followup->load(['customer', 'assignedUser', 'conversation.messages' => fn ($query) => $query->latest('id')->limit(5)]);

        return view('admin.followups.show', compact('followup'));
    }

    public function edit(Followup $followup): View
    {
        return view('admin.followups.form', $this->formData($followup));
    }

    public function update(Request $request, Followup $followup): RedirectResponse
    {
        $data = $this->validatedData($request);

        if (($data['status'] ?? null) === 'completed' && ! $followup->completed_at) {
            $data['completed_at'] = now();
        }

        if (($data['status'] ?? null) !== 'completed') {
            $data['completed_at'] = null;
        }

        $followup->update($data);

        return redirect()->route('admin.followups.show', $followup)->with('status', 'Follow-up berhasil diperbarui.');
    }

    public function send(Followup $followup, RetentionService $retentionService): RedirectResponse
    {
        try {
            $retentionService->sendFollowup($followup);
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['followup' => $exception->getMessage()]);
        }

        return back()->with('status', 'Follow-up berhasil dikirim via WhatsApp.');
    }

    public function complete(Request $request, Followup $followup, RetentionService $retentionService): RedirectResponse
    {
        $data = $request->validate(['result' => ['nullable', 'string', 'max:1000']]);
        $retentionService->complete($followup, $data['result'] ?? null);

        return back()->with('status', 'Follow-up berhasil diselesaikan.');
    }

    private function formData(Followup $followup): array
    {
        return [
            'followup' => $followup,
            'customers' => Customer::query()->orderBy('name')->limit(500)->get(['id', 'name', 'whatsapp_number']),
            'users' => User::query()->whereIn('role', ['owner', 'manager', 'admin', 'sales'])->where('status', 'active')->orderBy('name')->get(['id', 'name', 'role']),
            'conversations' => Conversation::query()->with('customer')->latest('last_message_at')->limit(500)->get(['id', 'customer_id', 'wa_chat_id', 'last_message_at']),
        ];
    }

    private function validatedData(Request $request): array
    {
        return $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'conversation_id' => ['nullable', 'exists:conversations,id'],
            'assigned_user_id' => ['nullable', 'exists:users,id'],
            'title' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'in:open,sent,completed'],
            'priority' => ['required', 'in:normal,high'],
            'due_at' => ['nullable', 'date'],
        ]);
    }

    private function defaultsFromQuery(Request $request): array
    {
        $defaults = [];

        if ($request->filled('customer_id')) {
            $customerId = (int) $request->integer('customer_id');
            if ($customerId > 0) {
                $defaults['customer_id'] = $customerId;
            }
        }

        if ($request->filled('conversation_id')) {
            $conversationId = (int) $request->integer('conversation_id');
            if ($conversationId > 0) {
                $defaults['conversation_id'] = $conversationId;
            }
        }

        if ($request->filled('assigned_user_id')) {
            $assignedUserId = (int) $request->integer('assigned_user_id');
            if ($assignedUserId > 0) {
                $defaults['assigned_user_id'] = $assignedUserId;
            }
        }

        if ($request->filled('title')) {
            $title = trim((string) $request->string('title'));
            if ($title !== '') {
                $defaults['title'] = Str::limit($title, 150);
            }
        }

        if ($request->has('notes')) {
            $notes = (string) $request->input('notes');
            $defaults['notes'] = Str::limit($notes, 2000);
        }

        if ($request->filled('status') && in_array($request->string('status')->toString(), ['open', 'sent', 'completed'], true)) {
            $defaults['status'] = $request->string('status')->toString();
        }

        if ($request->filled('priority') && in_array($request->string('priority')->toString(), ['normal', 'high'], true)) {
            $defaults['priority'] = $request->string('priority')->toString();
        }

        if ($request->filled('due_at')) {
            try {
                $defaults['due_at'] = Carbon::parse($request->string('due_at')->toString());
            } catch (\Exception) {
                // keep fallback due_at from model default
            }
        }

        return $defaults;
    }
}
