@extends('layouts.app')

@section('title', 'Promo & Voucher - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Promo" title="Promo & Voucher" description="Kelola promo aktif untuk booking, follow-up, dan campaign WhatsApp.">
        <x-slot name="actions"><a class="button button-primary" href="{{ route('admin.promos.create') }}"><i data-lucide="plus"></i> Tambah Promo</a></x-slot>
    </x-ui.page-header>
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Promo</th><th>Kode</th><th>Nilai</th><th>Periode</th><th>Kuota</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($promos as $promo)<tr><td data-label="Promo"><strong>{{ $promo->title }}</strong><br><span class="muted">{{ $promo->description ?: '-' }}</span></td><td data-label="Kode">{{ $promo->code ?: '-' }}</td><td data-label="Nilai">{{ $promo->type === 'percent' ? $promo->value.'%' : 'Rp '.number_format((float)$promo->value,0,',','.') }}</td><td data-label="Periode">{{ $promo->start_at?->format('d M Y') ?: '-' }} - {{ $promo->end_at?->format('d M Y') ?: '-' }}</td><td data-label="Kuota">{{ $promo->quota ? $promo->used_count.'/'.$promo->quota : 'Unlimited' }}</td><td data-label="Status"><span class="badge {{ $promo->isUsable() ? 'badge-green' : 'badge-gold' }}">{{ $promo->isUsable() ? 'usable' : 'inactive/expired' }}</span></td><td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.promos.edit', $promo) }}">Edit</a></td></tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada promo.</td></tr>@endforelse</tbody></table><div style="margin-top:1rem;">{{ $promos->links() }}</div></div></div>
@endsection
