@extends('layouts.app')

@section('title', 'WhatsApp Inbox - Gayatri CRM')

@section('content')
    @include('partials.admin-operations-assets')
    <x-ui.page-header
        eyebrow="WhatsApp Inbox"
        title="Percakapan Customer"
        description="Baca percakapan, ambil alih dari AI, dan tindak lanjuti pelanggan dari satu tempat."
    >
        <x-slot name="actions">
            <div class="inbox-refresh"><label><input type="checkbox" data-inbox-live checked> Perbarui otomatis</label><button type="button" class="button button-secondary" data-inbox-refresh>Perbarui</button><p data-inbox-refresh-status role="status">Setiap 15 detik, selama tidak ada draf.</p></div>
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

    <section class="inbox-layout {{ $selectedConversation ? 'is-thread' : '' }}" data-inbox>
        <aside class="card inbox-list-panel" aria-label="Daftar percakapan">
            <div class="card-body">
                <h2 class="section-title">Daftar percakapan</h2>
                <form method="GET" action="{{ route('admin.inbox') }}" style="display: grid; gap: .75rem;">
                    <div class="inbox-filter-search"><input class="input" aria-label="Cari percakapan" type="search" name="search" value="{{ request('search') }}" placeholder="Cari nama atau nomor"><button class="button button-secondary" type="submit" aria-label="Cari"><i data-lucide="search"></i></button></div><details class="inbox-filter-more" @if(request()->hasAny(['unread','need_followup','need_admin','ai_handled','assigned_user_id'])) open @endif><summary>Filter lanjutan</summary>
                    <div class="grid grid-2">
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>Belum dibaca</span>
                            <input type="checkbox" name="unread" value="1" @checked(request()->boolean('unread'))>
                        </label>
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>Perlu tindak lanjut</span>
                            <input type="checkbox" name="need_followup" value="1" @checked(request()->boolean('need_followup'))>
                        </label>
                    </div>
                    <div class="grid grid-2">
                        <label class="soft-list-item" style="padding: .65rem .75rem;">
                            <span>Ditangani AI</span>
                            <input type="checkbox" name="ai_handled" value="1" @checked(request()->boolean('ai_handled'))>
                        </label>
                        <select class="input" name="assigned_user_id" aria-label="Penanggung jawab">
                            <option value="">Semua admin</option>
                            @foreach ($admins as $admin)
                                <option value="{{ $admin->id }}" @selected((string) request('assigned_user_id') === (string) $admin->id)>{{ $admin->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button class="button button-secondary" type="submit"><i data-lucide="search"></i> Filter</button>
                    <label><input type="checkbox" name="need_admin" value="1" @checked(request()->boolean('need_admin'))> Menunggu bantuan admin</label></details>
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
                            href="{{ route('admin.inbox', array_merge(request()->except('refresh'), ['conversation' => $conversation->id])) }}"
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

        <div class="card inbox-thread-panel" id="inbox-thread" aria-label="Percakapan terpilih">
            @if ($selectedConversation)
                <div class="inbox-thread-header">
                    <div class="inbox-thread-name"><a class="button button-ghost inbox-mobile-back" href="{{ route('admin.inbox', request()->except(['conversation','refresh'])) }}">← Daftar chat</a>
                        <div class="avatar">{{ strtoupper(substr($selectedConversation->customer?->name ?? 'CU', 0, 2)) }}</div>
                        <div>
                            <strong>{{ $selectedConversation->customer?->name ?: $selectedConversation->wa_chat_id }}</strong>
                            <p class="muted" style="margin: .2rem 0 0;">
                                {{ $selectedConversation->status }} | {{ $selectedConversation->assignedUser?->name ?: 'Belum di-assign' }}
                            </p>
                        </div>
                    </div>
                    <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
                        @if(auth()->user()->hasRole(['owner','manager','admin']))
                        <a class="button button-secondary" href="{{ route('admin.bookings.create', ['customer_id' => $selectedConversation->customer_id, 'conversation_id' => $selectedConversation->id]) }}">Booking</a>
                        @endif
                        <button class="button button-secondary" type="button" data-profile-open>Profil & catatan</button>
                        <details class="inbox-tools"><summary class="button button-secondary">Kelola chat</summary><div class="inbox-tools-body">
                        @if(! app(\App\Services\AI\AiInteractionPolicy::class)->allowsLegacyAutomation())
                        <form method="POST" action="{{ route('admin.inbox.takeover',$selectedConversation) }}">@csrf @include('partials.inbox-return-fields')<input type="hidden" name="control_revision" value="{{ $selectedConversation->ai_control_revision }}"><button class="button button-secondary">Ambil alih percakapan</button></form>
                        @if(auth()->user()->hasRole(['owner','manager','admin','sales']) && in_array($selectedConversation->customer?->status,['lead','active']) && (!$selectedConversation->ai_enabled || in_array($selectedConversation->status,['human_handled','closed','escalated'])))
                        <form method="POST" action="{{ route('admin.inbox.resume',$selectedConversation) }}">@csrf @include('partials.inbox-return-fields')<input type="hidden" name="control_revision" value="{{ $selectedConversation->ai_control_revision }}"><button class="button button-primary">Lanjutkan AI · pesan baru</button></form>
                        @endif
                        @else
                        @php($takeoverOn = ! $selectedConversation->ai_enabled && $selectedConversation->assigned_user_id !== null)
                        <form method="POST" action="{{ route('admin.inbox.takeover', $selectedConversation) }}">
                            @csrf @include('partials.inbox-return-fields')
                            <button class="button {{ $takeoverOn ? 'button-primary' : 'button-secondary' }}" type="submit">
                                <i data-lucide="hand"></i> Takeover {{ $takeoverOn ? 'ON' : 'OFF' }}
                            </button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('admin.inbox.close', $selectedConversation) }}">
                            @csrf @include('partials.inbox-return-fields')
                            <button class="button button-ghost" type="submit"><i data-lucide="check-check"></i> Close</button>
                        </form>
                        @if(auth()->user()->hasRole(['owner','manager']))
                        <details><summary>Hapus history (owner/manager)</summary><form method="POST" action="{{ route('admin.inbox.delete-history', $selectedConversation) }}">@csrf @include('partials.inbox-return-fields')
                        <label>Alasan penghapusan<input class="input" name="reason" minlength="5" maxlength="500" required></label>
                        <label><input type="checkbox" name="confirmed" value="1" required> Saya menyetujui penghapusan riwayat chat ini.</label><button class="button button-ghost" type="submit">Hapus History</button></form></details>
                        @endif
                        </div></details>
                    </div>
                </div>

                @if(! app(\App\Services\AI\AiInteractionPolicy::class)->allowsLegacyAutomation())
                <div class="inbox-ai-state">
                    <strong>{{ $selectedConversation->ai_needs_attention ? 'Menunggu bantuan admin' : ($selectedConversation->ai_enabled ? 'Asisten dapat menjawab pesan baru' : 'Ditangani manual') }}</strong>
                    <details><summary>Alasan & jejak AI</summary>
                    @if($selectedConversation->ai_handoff_reason)<p>{{ \App\Services\AI\AiTraceService::REASONS[$selectedConversation->ai_handoff_reason] ?? 'Periksa percakapan untuk tindak lanjut.' }}</p>@endif
                    @if($aiDecision)@php($decisionInfo=app(\App\Services\AI\AiTraceService::class)->describe($aiDecision))
                    <p class="muted" style="margin:.5rem 0;">{{ $decisionInfo['domain_label'] }} · {{ $decisionInfo['reason_label'] }} · {{ $decisionInfo['state_label'] }}</p>
                    @if(auth()->user()->hasRole(['owner','manager','admin']))<a href="{{ route('admin.ai.logs.show',$aiDecision) }}">Telusuri keputusan dan pengiriman</a>@endif
                    @endif
                    @if(isset($aiContext['lookup']))<p class="muted">Pengecekan status sedang menunggu data pemilik. Detail kode dan nomor tetap dibatasi.</p>@endif
                    <small>Membuka percakapan menandai pesan terbaca. Permintaan bantuan tetap tercatat sampai diambil alih.</small></details>
                </div>
                @endif
                <div class="chat-messages" aria-label="Pesan terbaru" tabindex="0">
                    @if ($hiddenMessageCount > 0)
                        <div class="chat-history-limit muted">
                            Menampilkan {{ $visibleMessageLimit }} pesan terbaru. {{ $hiddenMessageCount }} pesan lama disembunyikan agar area chat tetap ringkas.
                        </div>
                    @endif
                    @forelse ($selectedConversation->messages as $message)
                        <div class="bubble {{ $message->sender_type === 'ai' ? 'bubble-ai' : ($message->direction === 'outgoing' ? 'bubble-outgoing' : 'bubble-incoming') }}">
                            <div class="inbox-message-text">{{ $message->content ?: '[Pesan tanpa teks]' }}</div>
                            <div class="muted" style="margin-top:.35rem; font-size:.72rem;">
                                {{ ucfirst($message->sender_type) }} • {{ $message->sent_at?->format('d M H:i') ?: $message->created_at?->format('d M H:i') }}
                                @if($message->failed_at)
                                    • <span style="color:#9b3b30;font-weight:700;">{{ $message->delivery_status === 'failed' ? 'Gagal' : 'Perlu diperiksa' }}: {{ \Illuminate\Support\Str::limit(\App\Services\WhatsApp\DeliveryFailure::safeText($message->failed_reason), 120) }}</span>
                                @endif
                            </div>
                            @if($message->failed_at && $message->direction === 'outgoing' && auth()->user()->hasRole(['owner','manager','admin']))
                                <a class="button button-secondary" href="{{ route('admin.whatsapp.monitor', ['source' => 'message']) }}" style="margin-top:.5rem;padding:.45rem .7rem;font-size:.8rem;">Tinjau kirim ulang</a>
                            @elseif(in_array($message->delivery_status, ['queued','sending'], true))
                                <small>{{ $message->delivery_status === 'queued' ? 'Dalam antrean' : 'Sedang dikirim' }}</small>
                            @endif
                        </div>
                    @empty
                        <x-ui.empty-state icon="messages-square" title="Belum Ada Pesan" description="Conversation ini belum memiliki histori pesan." />
                    @endforelse
                </div>

                <div class="reply-box" style="flex-direction:column;">
                    <form method="POST" action="{{ route('admin.inbox.reply', $selectedConversation) }}" class="inbox-reply-form">
                        @csrf @include('partials.inbox-return-fields')
                        <textarea class="input" name="message" rows="2" maxlength="5000" aria-label="Balasan WhatsApp" placeholder="Tulis balasan untuk pelanggan…" required>{{ old('message') }}</textarea>
                        <button class="button button-primary" type="submit"><i data-lucide="send"></i> Kirim</button>
                    </form>

                </div>
            @else
                <div class="card-body" style="height:100%;">
                    <x-ui.empty-state icon="message-circle" title="Pilih Conversation" description="Pilih salah satu chat di sisi kiri untuk membuka detail percakapan." />
                </div>
            @endif
        </div>


    </section>
    @if($selectedConversation)
        <dialog class="inbox-profile" data-inbox-profile @if($errors->hasAny(['internal_note','notes'])) data-profile-errors @endif aria-labelledby="inbox-profile-title">
            <div class="card-body">
                <div class="ops-actions" style="justify-content:space-between;"><h2 id="inbox-profile-title">Profil Customer</h2><button type="button" class="button button-secondary" data-profile-close>Tutup</button></div>
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
                        @csrf @include('partials.inbox-return-fields')
                        <label>
                            <span class="muted" style="display:block; margin-bottom:.4rem; font-weight:700;">Catatan Internal</span>
                            <textarea class="input" name="internal_note" rows="4" maxlength="2000" placeholder="Catatan hanya untuk tim internal...">{{ old('internal_note', $selectedConversation->internal_note) }}</textarea>
                        </label>
                        <button class="button button-secondary" type="submit"><i data-lucide="notebook-pen"></i> Simpan Note</button>
                    </form>

                    <form method="POST" action="{{ route('admin.inbox.followup', $selectedConversation) }}" >
                        @csrf @include('partials.inbox-return-fields')
                        <input class="input" type="text" name="notes" aria-label="Catatan tindak lanjut" maxlength="2000" placeholder="Catatan follow-up, contoh: hubungi lagi sore ini">
                        <button class="button button-secondary" type="submit"><i data-lucide="phone-forwarded"></i> Mark Follow-Up</button>
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
        </dialog>
    @endif
@endsection
