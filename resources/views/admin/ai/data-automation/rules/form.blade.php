@extends('layouts.app')

@section('title', ($rule->exists ? 'Edit Rule' : 'Tambah Rule') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="{{ $rule->exists ? 'Edit Rule' : 'Tambah Rule' }}" description="Gunakan mode need_confirmation untuk data penting seperti booking.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.rules.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body"><form method="POST" action="{{ $rule->exists ? route('admin.ai.data-automation.rules.update', $rule) : route('admin.ai.data-automation.rules.store') }}" class="grid grid-2">@csrf @if($rule->exists) @method('PUT') @endif
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Nama Rule</span><input class="input" name="name" value="{{ old('name', $rule->name) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Trigger Event</span><input class="input" name="trigger_event" value="{{ old('trigger_event', $rule->trigger_event) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Target Entity</span><select class="input" name="target_entity" required>@foreach(['customer','booking','followup','reminder'] as $target)<option value="{{ $target }}" @selected(old('target_entity', $rule->target_entity)===$target)>{{ $target }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Action</span><input class="input" name="action" value="{{ old('action', $rule->action) }}" placeholder="create_booking_draft" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Mode</span><select class="input" name="mode" required>@foreach(['auto_create','need_confirmation','human_only'] as $mode)<option value="{{ $mode }}" @selected(old('mode', $rule->mode)===$mode)>{{ $mode }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Confidence Threshold</span><input class="input" type="number" name="confidence_threshold" min="0" max="1" step="0.01" value="{{ old('confidence_threshold', $rule->confidence_threshold) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Required Fields</span><input class="input" name="required_fields" value="{{ old('required_fields', implode(', ', $rule->required_fields ?? [])) }}" placeholder="service_id, booking_date, start_time"></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Forbidden Intents</span><input class="input" name="forbidden_intents" value="{{ old('forbidden_intents', implode(', ', $rule->forbidden_intents ?? [])) }}" placeholder="medical, refund, complaint"></label>
        <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Condition Intents</span><input class="input" name="condition_intents" value="{{ old('condition_intents', implode(', ', $rule->conditions['intents'] ?? [])) }}" placeholder="booking_request, pricing_info"></label>
        <label style="grid-column:1/-1;display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $rule->is_active))> Rule aktif</label>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.rules.index') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
    </form></div></div>
@endsection
