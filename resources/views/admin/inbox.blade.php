@extends('layouts.app')

@section('title', 'WhatsApp Inbox - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="WhatsApp Inbox"
        title="Percakapan Customer"
        description="Kelola chat WhatsApp, takeover dari AI, buat booking cepat, dan lihat profil customer dalam satu layar."
    >
        <x-slot name="actions">
            <div class="muted" style="font-weight: 700;">Auto-refresh 15 detik aktif untuk chat baru.</div>
        </x-slot>
    </x-ui.page-header>

    @if (session('status'))
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(143,174,139,.55);">
            <div class="card-body" style="color: #3f6d3d;">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="card" style="margin-bottom: 1rem; border-color: rgba(201,122,106,.55);">
            <div class="card-body" style="color: #7a3228;">{{ $errors->first() }}</div>
        </div>
    @endif

    <section class="chat-layout chat-layout-vertical">
        <aside class="card inbox-customer-section">
            <div class="card-body">
                <h2 class="section-title">Filter & Customer</h2>
                <form method="GET" action="{{ route('admin.inbox') }}" style="display: grid; gap: .75rem;">
                    <input class="input" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama atau nomor">
                    <div class="grid grid-2">
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>Unread</span>
                            <input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))>
                        </label>
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>Need Follow-Up</span>
                            <input type="checkbox" name="need_followup" value="1" @checked(request()->boolean('need_followup'))>
                        </label>
                    </div>
                    <div class="grid grid-2">
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>AI Handled</span>
                            <input type="checkbox" name="ai_handled" value="1" @checked(request()->boolean('ai_handled'))>
                        </label>
                        <select class="input" name="assigned_user_id">
                            <option value="">Semua admin</option>
                            @foreach ($admins as $admin)
                                <option value="{{ $admin->id }}" @selected((string) request('assigned_user_id') === (string) $admin->id)>{{ $admin->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
                </form>

                <div class="soft-list inbox-conversation-list">
                    @forelse ($conversations as $conversation)
                        @php
                            $customer = $conversation->customer;
                            $preview = $conversation->latestMessage;
                            $initials = strtoupper(substr($customer?->name ?? 'CU', 0, 2));
                        @endphp
                        <a
                            class="conversation-item {{ $selectedConversation?->id === $conversation->id ? 'is-active' : '' }}"
                            href="{{ route('admin.inbox', array_merge(request()->except('page'), ['conversation' => $conversation->id])) }}"
                        >
                            <div class="avatar">{{ $initials }}</div>
                            <div style="min-width: 0; width: 100%;">
                                <div style="display:flex; justify-content:space-between; gap:.5rem; align-items:flex-start;">
                                    <strong>{{ $customer?->name ?: $conversation->wa_chat_id }}</strong>
                                    @if ($conversation->unread_count > 0)
                                        <span class="badge badge-gold">{{ $conversation->unread_count }}</span>
                                    @endif
                                </div>
                                <p class="muted" style="margin: .2rem 0 0;">{{ \Illuminate\Support\Str::limit($preview?->content ?: 'Belum ada pesan.', 42) }}</p>
                                <div style="display:flex; gap:.35rem; flex-wrap:wrap; margin-top:.45rem;">
                                    @if ($conversation->ai_enabled)
                                        <span class="badge badge-brown">AI</span>
                                    @endif
                                    @if ($conversation->status === 'need_followup')
                                        <span class="badge badge-green">Follow-Up</span>
                                    @endif
                                    @if ($conversation->assignedUser)
                                        <span class="badge badge-gold">{{ $conversation->assignedUser->name }}</span>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @empty
                        <x-ui.empty-state icon="inbox" title="Inbox Kosong" description="Belum ada conversation yang masuk dari WhatsApp." />
                    @endforelse
                </div>
                <div style="margin-top: 1rem;">{{ $conversations->links() }}</div>
            </div>
        </aside>

        <div class="card chat-window">
            @if ($selectedConversation)
                <div class="card-body" style="display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid rgba(216,195,165,.68); gap:1rem; flex-wrap:wrap;">
                    <div style="display: flex; align-items: center; gap: .75rem;">
                        <div class="avatar">{{ strtoupper(substr($selectedConversation->customer?->name ?? 'CU', 0, 2)) }}</div>
                        <div>
                            <strong>{{ $selectedConversation->customer?->name ?: $selectedConversation->wa_chat_id }}</strong>
                            <p class="muted" style="margin: .2rem 0 0;">
                                {{ $selectedConversation->status }} | {{ $selectedConversation->assignedUser?->name ?: 'Belum di-assign' }}
                            </p>
                        </div>
                    </div>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                        <a class="button button-primary" href="{{ route('admin.bookings.create', ['customer_id' => $selectedConversation->customer_id, 'conversation_id' => $selectedConversation->id]) }}"><i data-lucide="calendar-plus"></i> Booking</a>
                        @php($takeoverOn = ! $selectedConversation->ai_enabled && $selectedConversation->assigned_user_id !== null)
                        <form method="POST" action="{{ route('admin.inbox.takeover', $selectedConversation) }}">
                            @csrf
                            <button class="button {{ $takeoverOn ? 'button-primary' : 'button-secondary' }}" type="submit">
                                <i data-lucide="hand"></i> Takeover {{ $takeoverOn ? 'ON' : 'OFF' }}
                            </button>
                        </form>
                        <form method="POST" action="{{ route('admin.inbox.close', $selectedConversation) }}">
                            @csrf
                            <button class="button button-ghost" type="submit"><i data-lucide="check-check"></i> Close</button>
                        </form>
                        <form method="POST" action="{{ route('admin.inbox.delete-history', $selectedConversation) }}" onsubmit="return confirm('Hapus seluruh history chat pada conversation ini? Profil customer dan catatan internal tetap disimpan.');">
                            @csrf
                            <button class="button button-ghost" type="submit" style="color:#7a3228;"><i data-lucide="trash-2"></i> Hapus History</button>
                        </form>
                    </div>
                </div>

                <div class="chat-messages">
                    @if ($hiddenMessageCount > 0)
                        <div class="chat-history-limit muted">
                            Menampilkan {{ $visibleMessageLimit }} pesan terbaru. {{ $hiddenMessageCount }} pesan lama disembunyikan agar area chat tetap ringkas.
                        </div>
                    @endif
                    @forelse ($selectedConversation->messages as $message)
                        <div class="bubble {{ $message->sender_type === 'ai' ? 'bubble-ai' : ($message->direction === 'outgoing' ? 'bubble-outgoing' : 'bubble-incoming') }}">
                            {{ $message->content ?: '[Pesan tanpa teks]' }}
                            <div class="muted" style="margin-top:.35rem; font-size:.72rem;">
                                {{ ucfirst($message->sender_type) }} • {{ $message->sent_at?->format('d M H:i') ?: $message->created_at?->format('d M H:i') }}
                                @if($message->failed_at)
                                    • <span style="color:#9b3b30;font-weight:700;">Gagal: {{ \Illuminate\Support\Str::limit($message->failed_reason ?: 'WAHA error', 80) }}</span>
                                @endif
                            </div>
                            @if($message->failed_at && $message->direction === 'outgoing')
                                <form method="POST" action="{{ route('admin.inbox.messages.retry', $message) }}" style="margin-top:.5rem;">
                                    @csrf
                                    <button class="button button-secondary" type="submit" style="padding:.45rem .7rem;font-size:.8rem;"><i data-lucide="refresh-cw"></i> Retry</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state icon="messages-square" title="Belum Ada Pesan" description="Conversation ini belum memiliki histori pesan." />
                    @endforelse
                </div>

                <div class="reply-box" style="flex-direction:column;">
                    <form method="POST" action="{{ route('admin.inbox.reply', $selectedConversation) }}" style="display:flex; gap:.7rem; width:100%;">
                        @csrf
                        <input class="input" type="text" name="message" placeholder="Tulis balasan WhatsApp-friendly untuk customer..." required>
                        <button class="button button-primary" type="submit"><i data-lucide="send"></i> Kirim</button>
                    </form>
                    <form method="POST" action="{{ route('admin.inbox.followup', $selectedConversation) }}" style="display:flex; gap:.7rem; width:100%;">
                        @csrf
                        <input class="input" type="text" name="notes" placeholder="Catatan follow-up, contoh: hubungi lagi sore ini">
                        <button class="button button-secondary" type="submit"><i data-lucide="phone-forwarded"></i> Mark Follow-Up</button>
                    </form>
                </div>
            @else
                <div class="card-body" style="height:100%;">
                    <x-ui.empty-state icon="message-circle" title="Pilih Conversation" description="Pilih salah satu chat di sisi kiri untuk membuka detail percakapan." />
                </div>
            @endif
        </div>

        <aside class="card customer-side">
            <div class="card-body">
                <h2 class="section-title">Profil Customer</h2>
                @if ($selectedConversation?->customer)
                    @php($customer = $selectedConversation->customer)
                    @php($lastBooking = $customer->bookings->sortByDesc('booking_date')->first())
                    <div class="soft-list">
                        <div class="soft-list-item"><span>Status</span><span class="badge badge-gold">{{ ucfirst(str_replace('_', ' ', $customer->status)) }}</span></div>
                        <div class="soft-list-item"><span>Nomor</span><strong>{{ $customer->whatsapp_number ?: ($customer->phone ?: '-') }}</strong></div>
                        <div class="soft-list-item"><span>Kota</span><strong>{{ $customer->city ?: '-' }}</strong></div>
                        <div class="soft-list-item"><span>Booking terakhir</span><strong>{{ $lastBooking?->booking_code ?: 'Belum ada' }}</strong></div>
                        <div class="soft-list-item"><span>Follow-up aktif</span><strong>{{ $customer->followups->where('status', 'open')->count() }}</strong></div>
                    </div>

                    <form method="POST" action="{{ route('admin.inbox.note', $selectedConversation) }}" style="margin-top: 1rem; display:grid; gap:.75rem;">
                        @csrf
                        <label>
                            <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Catatan Internal</span>
                            <textarea class="input" name="internal_note" rows="4" placeholder="Catatan hanya untuk tim internal...">{{ old('internal_note', $selectedConversation->internal_note) }}</textarea>
                        </label>
                        <button class="button button-secondary" type="submit"><i data-lucide="notebook-pen"></i> Simpan Note</button>
                    </form>

                    <div class="card" style="margin-top: 1rem;">
                        <div class="card-body">
                            <h2 class="section-title">Follow-Up Task</h2>
                            <div class="soft-list">
                                @forelse ($customer->followups->take(3) as $followup)
                                    <div class="soft-list-item" style="align-items:flex-start;">
                                        <div>
                                            <strong>{{ $followup->title }}</strong>
                                            <p class="muted" style="margin:.25rem 0 0;">{{ $followup->notes ?: '-' }}</p>
                                        </div>
                                    </div>
                                @empty
                                    <p class="muted">Belum ada follow-up task.</p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @else
                    <x-ui.empty-state icon="user-round" title="Profil Belum Dipilih" description="Pilih conversation untuk melihat profil customer dan catatan internal." />
                @endif
            </div>
        </aside>
    </section>
@endsection

@push('scripts')
    <script>
        setTimeout(function () {
            const url = new URL(window.location.href);
            url.searchParams.set('refresh', Date.now().toString());
            window.location.replace(url.toString());
        }, 15000);
    </script>
@endpush
