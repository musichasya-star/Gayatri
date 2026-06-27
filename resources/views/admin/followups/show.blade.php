@extends('layouts.app')

@section('title', 'Detail Follow-Up - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Follow-Up" title="Detail Follow-Up" description="Review task, customer, catatan, dan action follow-up.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.followups.index') }}"><i data-lucide="arrow-left"></i> Kembali</a><a class="button button-primary" href="{{ route('admin.followups.edit', $followup) }}"><i data-lucide="edit"></i> Edit</a></x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <section class="grid grid-2">
        <div class="card"><div class="card-body"><h2 class="section-title">Task</h2><ul class="soft-list"><li class="soft-list-item"><span>Title</span><strong>{{ $followup->title }}</strong></li><li class="soft-list-item"><span>Status</span><span class="badge {{ $followup->status === 'completed' ? 'badge-green' : 'badge-gold' }}">{{ $followup->status }}</span></li><li class="soft-list-item"><span>Priority</span><span class="badge {{ $followup->priority === 'high' ? 'badge-red' : 'badge-brown' }}">{{ $followup->priority }}</span></li><li class="soft-list-item"><span>Due</span><strong>{{ $followup->due_at?->format('d M Y H:i') ?: '-' }}</strong></li><li class="soft-list-item"><span>Completed</span><strong>{{ $followup->completed_at?->format('d M Y H:i') ?: '-' }}</strong></li><li class="soft-list-item"><span>Assigned</span><strong>{{ $followup->assignedUser?->name ?: '-' }}</strong></li></ul></div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Customer</h2><ul class="soft-list"><li class="soft-list-item"><span>Nama</span><strong>{{ $followup->customer?->name ?: '-' }}</strong></li><li class="soft-list-item"><span>WhatsApp</span><strong>{{ $followup->customer?->whatsapp_number ?: '-' }}</strong></li><li class="soft-list-item"><span>Status</span><strong>{{ $followup->customer?->status ?: '-' }}</strong></li><li class="soft-list-item"><span>Conversation</span><strong>{{ $followup->conversation?->wa_chat_id ?: '-' }}</strong></li></ul></div></div>
    </section>

    <div class="card" style="margin-top:1rem;"><div class="card-body"><h2 class="section-title">Catatan / Template WA</h2><p style="white-space:pre-wrap;">{{ $followup->notes ?: '-' }}</p></div></div>

    @if($followup->status !== 'completed')
        <section class="grid grid-2" style="margin-top:1rem;">
            <div class="card"><div class="card-body"><h2 class="section-title">Kirim WhatsApp</h2><p class="muted">Mengirim catatan follow-up ke customer dan mengubah status menjadi sent.</p><form method="POST" action="{{ route('admin.followups.send', $followup) }}">@csrf<button class="button button-secondary" type="submit"><i data-lucide="send"></i> Kirim WA</button></form></div></div>
            <div class="card"><div class="card-body"><h2 class="section-title">Complete</h2><form method="POST" action="{{ route('admin.followups.complete', $followup) }}" class="grid">@csrf<label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Hasil Follow-Up</span><textarea class="input" name="result" rows="4" placeholder="Contoh: Customer tertarik booking minggu depan."></textarea></label><button class="button button-primary" type="submit"><i data-lucide="check"></i> Tandai Complete</button></form></div></div>
        </section>
    @endif

    <div class="card" style="margin-top:1rem;"><div class="card-body"><h2 class="section-title">Pesan Terakhir Conversation</h2><ul class="soft-list">@forelse($followup->conversation?->messages ?? [] as $message)<li class="soft-list-item"><span>{{ $message->created_at->format('d M H:i') }} - {{ $message->direction }}</span><strong>{{ Str::limit($message->content ?: '-', 120) }}</strong></li>@empty<li class="soft-list-item"><span>Pesan</span><strong>Belum ada pesan conversation.</strong></li>@endforelse</ul></div></div>
@endsection
