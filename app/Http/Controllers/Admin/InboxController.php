<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiConversationContext;
use App\Services\CRM\InboxAccess;
use App\Services\CRM\InboxService;
use App\Services\WhatsApp\MonitorRetry;
use App\Support\OperationalQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function __construct(private readonly InboxService $inboxService) {}

    public function index(Request $request): View
    {
        $request->validate(['conversation' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:150'],
            'page' => ['nullable', 'integer', 'min:1'], 'unread' => ['nullable', 'boolean'], 'need_followup' => ['nullable', 'boolean'],
            'need_admin' => ['nullable', 'boolean'], 'ai_handled' => ['nullable', 'boolean'], 'assigned_user_id' => ['nullable', 'integer', 'min:1']]);
        $visibleMessageLimit = 60;
        $conversations = InboxAccess::query($request->user())
            ->with([
                'customer.bookings' => fn ($query) => $query->latest('booking_date'),
                'latestMessage',
                'assignedUser',
            ])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->string('search');

                $query->where(function ($query) use ($search) {
                    $query->where('wa_chat_id', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('whatsapp_number', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->boolean('unread'), fn ($query) => $query->where('unread_count', '>', 0))
            ->when($request->boolean('need_followup'), fn ($query) => $query->where('status', 'need_followup'))
            ->when($request->boolean('need_admin'), fn ($query) => $query->where('ai_needs_attention', true))
            ->when($request->boolean('ai_handled'), fn ($query) => $query->where('ai_enabled', true)->whereNull('assigned_user_id'))
            ->when($request->filled('assigned_user_id'), fn ($query) => $query->where('assigned_user_id', $request->integer('assigned_user_id')))
            ->orderByDesc('last_message_at')
            ->paginate(15)
            ->withQueryString();

        $selectedConversation = $request->filled('conversation')
            ? InboxAccess::query($request->user())->with([
                'customer.bookings' => fn ($query) => $query->latest('booking_date'),
                'customer.followups' => fn ($query) => $query->latest(),
                'assignedUser',
            ])->find($request->integer('conversation'))
            : null;
        abort_if($request->filled('conversation') && ! $selectedConversation, 404);

        $totalMessages = 0;
        $hiddenMessageCount = 0;
        if ($selectedConversation && $selectedConversation->unread_count > 0) {
            $selectedConversation->update(['unread_count' => 0]);
            $selectedConversation->unread_count = 0;
        }

        if ($selectedConversation) {
            $totalMessages = $selectedConversation->messages()->count();
            $messages = $selectedConversation->messages()
                ->orderByDesc('id')
                ->limit($visibleMessageLimit)
                ->get()
                ->sortBy('id')
                ->values();
            $hiddenMessageCount = max(0, $totalMessages - $messages->count());
            $selectedConversation->setRelation('messages', $messages);
        }

        return view('admin.inbox', [
            'conversations' => $conversations,
            'selectedConversation' => $selectedConversation,
            'visibleMessageLimit' => $visibleMessageLimit,
            'totalMessages' => $totalMessages,
            'hiddenMessageCount' => $hiddenMessageCount,
            'aiDecision' => $selectedConversation ? AiLog::with('outgoingMessage')->where('conversation_id', $selectedConversation->id)->latest('id')->first() : null,
            'aiContext' => $selectedConversation ? app(AiConversationContext::class)->read($selectedConversation) : [],
            'admins' => User::query()
                ->whereIn('role', ['owner', 'manager', 'admin', 'sales'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $this->inboxService->sendReply($conversation, $validated['message'], $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', 'Balasan masuk antrean. Periksa status pengiriman pada pesan.');
    }

    public function takeover(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $this->inboxService->takeover($conversation, $request->user(), $request);

        $conversation->refresh();

        return redirect()
            ->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])
            ->with('status', 'Percakapan sudah diambil alih. AI berhenti membalas sampai dilanjutkan secara eksplisit.');
    }

    public function resume(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $this->inboxService->resume($conversation, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', 'AI dilanjutkan untuk pesan baru. Pesan lama tidak dijawab ulang.');
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $this->inboxService->close($conversation, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', 'Conversation berhasil ditutup.');
    }

    public function followup(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->inboxService->markFollowup($conversation, $request->user(), $request, $validated['notes'] ?? null);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', 'Conversation berhasil ditandai follow-up.');
    }

    public function note(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $validated = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inboxService->saveInternalNote($conversation, $request->user(), $request, (string) ($validated['internal_note'] ?? ''));

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', 'Catatan internal berhasil disimpan.');
    }

    public function deleteHistory(Request $request, Conversation $conversation): RedirectResponse
    {
        InboxAccess::authorize($conversation, $request->user());
        $deletedCount = $this->inboxService->deleteHistory($conversation, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id, ...OperationalQueue::inbox($request)])->with('status', "History chat berhasil dihapus ({$deletedCount} pesan).");
    }

    public function retry(Request $request, Message $message): RedirectResponse
    {
        InboxAccess::authorize($message->conversation, $request->user());
        app(MonitorRetry::class)->retry('message', $message->id, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $message->conversation_id])->with('status', 'Retry pesan WhatsApp dijadwalkan.');
    }
}
