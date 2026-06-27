@extends('layouts.app')

@php($filters = $campaign->audience_filters ?? [])

@section('title', ($campaign->exists ? 'Detail Campaign' : 'Buat Campaign') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Campaign" title="{{ $campaign->exists ? 'Detail Campaign' : 'Buat Campaign' }}" description="Gunakan placeholder {name}, {promo_code}, dan {promo_title} untuk personalisasi pesan.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.campaigns.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="grid grid-2">
        <div class="card"><div class="card-body"><h2 class="section-title">Builder</h2><form method="POST" action="{{ $campaign->exists ? route('admin.campaigns.update', $campaign) : route('admin.campaigns.store') }}" class="grid grid-2">@csrf @if($campaign->exists) @method('PUT') @endif
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Nama Campaign</span><input class="input" name="name" value="{{ old('name', $campaign->name) }}" @disabled($campaign->exists && $campaign->status !== 'draft') required></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Promo</span><select class="input" name="promo_id" @disabled($campaign->exists && $campaign->status !== 'draft')><option value="">Tanpa promo</option>@foreach($promos as $promo)<option value="{{ $promo->id }}" @selected(old('promo_id', $campaign->promo_id)==$promo->id)>{{ $promo->title }} {{ $promo->code ? '('.$promo->code.')' : '' }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status Customer</span><select class="input" name="filter_status" @disabled($campaign->exists && $campaign->status !== 'draft')><option value="">Semua status</option>@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('filter_status', $filters['status'] ?? '')===$status)>{{ $status }}</option>@endforeach</select></label>
            <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tag</span><input class="input" name="filter_tag" value="{{ old('filter_tag', $filters['tag'] ?? '') }}" placeholder="baby_spa" @disabled($campaign->exists && $campaign->status !== 'draft')></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Service Interest</span><input class="input" name="filter_service_interest" value="{{ old('filter_service_interest', $filters['service_interest'] ?? '') }}" placeholder="Baby Spa / massage / category layanan" @disabled($campaign->exists && $campaign->status !== 'draft')></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Inactive Days</span><select class="input" name="filter_inactive_days" @disabled($campaign->exists && $campaign->status !== 'draft')><option value="">Tidak difilter</option>@foreach([30,60,90] as $days)<option value="{{ $days }}" @selected((int) old('filter_inactive_days', $filters['inactive_days'] ?? 0)===$days)>Inactive {{ $days }} hari</option>@endforeach</select></label>
            <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Template Pesan</span><textarea class="input" name="message_template" rows="8" @disabled($campaign->exists && $campaign->status !== 'draft') required>{{ old('message_template', $campaign->message_template) }}</textarea></label>
            @if(! $campaign->exists || $campaign->status === 'draft')<div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Draft</button></div>@endif
        </form></div></div>
        <div class="card"><div class="card-body"><h2 class="section-title">Schedule & Preview</h2>
            <p class="muted">Preview saat ini menemukan {{ $previewTargets->count() }} customer. Setelah campaign dijadwalkan, recipient dikunci agar hasil pengiriman konsisten.</p>
            @if($campaign->exists && $campaign->status === 'draft' && in_array(auth()->user()->role, ['owner','manager'], true))<form method="POST" action="{{ route('admin.campaigns.approve', $campaign) }}" style="margin:1rem 0;">@csrf<button class="button button-primary" type="submit"><i data-lucide="check-circle"></i> Approve Campaign</button></form>@endif
            @if($campaign->exists && $campaign->status === 'approved')<form method="POST" action="{{ route('admin.campaigns.schedule', $campaign) }}" class="grid grid-2" style="margin:1rem 0;">@csrf<label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Jadwalkan</span><input class="input" type="datetime-local" name="scheduled_at" value="{{ now()->format('Y-m-d\TH:i') }}"></label><button class="button button-primary" type="submit" style="align-self:end;"><i data-lucide="send"></i> Schedule</button></form>@endif
            <table class="table-card"><thead><tr><th>Customer</th><th>WhatsApp</th><th>Status</th></tr></thead><tbody>@forelse($previewTargets->take(10) as $customer)<tr><td data-label="Customer">{{ $customer->name }}</td><td data-label="WhatsApp">{{ $customer->whatsapp_number }}</td><td data-label="Status">{{ $customer->status }}</td></tr>@empty<tr><td colspan="3" style="text-align:center;padding:2rem;">Belum ada target.</td></tr>@endforelse</tbody></table>
        </div></div>
    </div>
@endsection
