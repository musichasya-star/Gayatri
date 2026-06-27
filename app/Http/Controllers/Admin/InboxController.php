<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\CRM\InboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function __construct(private readonly InboxService $inboxService) {}

    public function index(Request $request): View
    {
        $visibleMessageLimit = 60;
        $conversations = Conversation::query()
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
            ->when($request->boolean('ai_handled'), fn ($query) => $query->where('ai_enabled', true)->whereNull('assigned_user_id'))
            ->when($request->filled('assigned_user_id'), fn ($query) => $query->where('assigned_user_id', $request->integer('assigned_user_id')))
            ->orderByDesc('last_message_at')
            ->paginate(15)
            ->withQueryString();

        $selectedConversation = $request->filled('conversation')
            ? Conversation::with([
                'customer.bookings' => fn ($query) => $query->latest('booking_date'),
                'customer.followups' => fn ($query) => $query->latest(),
                'assignedUser',
            ])->find($request->integer('conversation'))
            : $conversations->getCollection()->first()?->load([
                'customer.bookings' => fn ($query) => $query->latest('booking_date'),
                'customer.followups' => fn ($query) => $query->latest(),
                'assignedUser',
            ]);

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
            'admins' => User::query()
                ->whereIn('role', ['owner', 'manager', 'admin', 'sales'])
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $this->inboxService->sendReply($conversation, $validated['message'], $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id])->with('status', 'Balasan berhasil dikirim.');
    }

    public function takeover(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->inboxService->takeover($conversation, $request->user(), $request);

        $conversation->refresh();

        return redirect()
            ->route('admin.inbox', ['conversation' => $conversation->id])
            ->with('status', $conversation->ai_enabled ? 'Takeover OFF. AI aktif kembali untuk conversation ini.' : 'Takeover ON. Conversation berhasil diambil alih oleh admin.');
    }

    public function close(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->inboxService->close($conversation, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id])->with('status', 'Conversation berhasil ditutup.');
    }

    public function followup(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->inboxService->markFollowup($conversation, $request->user(), $request, $validated['notes'] ?? null);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id])->with('status', 'Conversation berhasil ditandai follow-up.');
    }

    public function note(Request $request, Conversation $conversation): RedirectResponse
    {
        $validated = $request->validate([
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->inboxService->saveInternalNote($conversation, $request->user(), $request, (string) ($validated['internal_note'] ?? ''));

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id])->with('status', 'Catatan internal berhasil disimpan.');
    }

    public function deleteHistory(Request $request, Conversation $conversation): RedirectResponse
    {
        $deletedCount = $this->inboxService->deleteHistory($conversation, $request->user(), $request);

        return redirect()->route('admin.inbox', ['conversation' => $conversation->id])->with('status', "History chat berhasil dihapus ({$deletedCount} pesan).");
    }

    public function retry(Request $request, Message $message): RedirectResponse
    {
        if ($message->direction !== 'outgoing' || ! $message->failed_at) {
            return back()->withErrors(['message' => 'Hanya pesan outgoing yang gagal yang bisa di-retry.']);
        }

        SendWhatsAppMessageJob::dispatch(
            $message->conversation_id,
            $message->user_id,
            $message->sender_type,
            (string) $message->content,
            null,
            $message->id,
        );

        return redirect()->route('admin.inbox', ['conversation' => $message->conversation_id])->with('status', 'Retry pesan WhatsApp dijadwalkan.');
    }
}
