@extends('layouts.app')

@section('title', ($followup->exists ? 'Edit' : 'Buat').' Follow-Up - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Follow-Up" title="{{ $followup->exists ? 'Edit Follow-Up' : 'Buat Follow-Up' }}" description="Atur customer, PIC, jadwal, catatan, dan status follow-up.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.followups.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>

    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <form method="POST" action="{{ $followup->exists ? route('admin.followups.update', $followup) : route('admin.followups.store') }}" class="card">
        @csrf
        @if($followup->exists) @method('PUT') @endif
        <div class="card-body grid">
            <section class="grid grid-2">
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Customer</span><select class="input" name="customer_id" required><option value="">Pilih customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((int) old('customer_id', $followup->customer_id) === $customer->id)>{{ $customer->name }} - {{ $customer->whatsapp_number }}</option>@endforeach</select></label>
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Conversation WA</span><select class="input" name="conversation_id"><option value="">Auto / tanpa conversation</option>@foreach($conversations as $conversation)<option value="{{ $conversation->id }}" @selected((int) old('conversation_id', $followup->conversation_id) === $conversation->id)>#{{ $conversation->id }} - {{ $conversation->customer?->name ?: $conversation->wa_chat_id }}</option>@endforeach</select></label>
            </section>
            <section class="grid grid-2">
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Assigned To</span><select class="input" name="assigned_user_id"><option value="">Assign ke saya / kosong</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((int) old('assigned_user_id', $followup->assigned_user_id) === $user->id)>{{ $user->name }} ({{ $user->role }})</option>@endforeach</select></label>
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Due At</span><input class="input" type="datetime-local" name="due_at" value="{{ old('due_at', $followup->due_at?->format('Y-m-d\TH:i')) }}"></label>
            </section>
            <section class="grid grid-3">
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status</span><select class="input" name="status" required>@foreach(['open','sent','completed'] as $status)<option value="{{ $status }}" @selected(old('status', $followup->status) === $status)>{{ $status }}</option>@endforeach</select></label>
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Priority</span><select class="input" name="priority" required>@foreach(['normal','high'] as $priority)<option value="{{ $priority }}" @selected(old('priority', $followup->priority) === $priority)>{{ $priority }}</option>@endforeach</select></label>
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Title</span><input class="input" name="title" value="{{ old('title', $followup->title) }}" required maxlength="150"></label>
            </section>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Catatan / Template WA</span><textarea class="input" name="notes" rows="7" maxlength="2000">{{ old('notes', $followup->notes) }}</textarea></label>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button><a class="button button-secondary" href="{{ route('admin.followups.index') }}">Batal</a></div>
        </div>
    </form>
@endsection
