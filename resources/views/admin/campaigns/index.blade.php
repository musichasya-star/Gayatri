@extends('layouts.app')

@section('title', 'Campaigns - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Campaign" title="Campaign WhatsApp" description="Buat draft campaign, jadwalkan target segment, dan pantau hasil pengiriman.">
        <x-slot name="actions"><a class="button button-primary" href="{{ route('admin.campaigns.create') }}"><i data-lucide="plus"></i> Buat Campaign</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Campaign</th><th>Promo</th><th>Status</th><th>Schedule</th><th>Recipients</th><th>Hasil</th><th>Aksi</th></tr></thead><tbody>@forelse($campaigns as $campaign)<tr><td data-label="Campaign"><strong>{{ $campaign->name }}</strong><br><span class="muted">{{ Str::limit($campaign->message_template, 90) }}</span></td><td data-label="Promo">{{ $campaign->promo?->title ?: '-' }}</td><td data-label="Status"><span class="badge badge-gold">{{ $campaign->status }}</span></td><td data-label="Schedule">{{ $campaign->scheduled_at?->format('d M Y H:i') ?: '-' }}</td><td data-label="Recipients">{{ $campaign->recipient_count }}</td><td data-label="Hasil">{{ $campaign->sent_count }} sent / {{ $campaign->failed_count }} failed</td><td data-label="Aksi" style="display:flex;gap:.5rem;flex-wrap:wrap;"><a class="button button-secondary" href="{{ route('admin.campaigns.edit', $campaign) }}">Detail</a>@if(in_array($campaign->status, ['draft','approved','scheduled'], true))<form method="POST" action="{{ route('admin.campaigns.cancel', $campaign) }}">@csrf<button class="button button-secondary" type="submit">Cancel</button></form>@endif</td></tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada campaign.</td></tr>@endforelse</tbody></table><div style="margin-top:1rem;">{{ $campaigns->links() }}</div></div></div>
@endsection
