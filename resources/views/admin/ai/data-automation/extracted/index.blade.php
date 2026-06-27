@extends('layouts.app')

@section('title', 'Extracted Data - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Extracted Data" description="Hasil ekstraksi intent dan data customer/booking/follow-up dari chat WhatsApp." />
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Waktu</th><th>Customer</th><th>Intent</th><th>Confidence</th><th>Missing</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($items as $item)<tr><td data-label="Waktu">{{ $item->created_at->format('d M Y H:i') }}</td><td data-label="Customer">{{ $item->customer?->name ?: '-' }}</td><td data-label="Intent"><span class="badge badge-brown">{{ $item->intent }}</span></td><td data-label="Confidence">{{ number_format((float)$item->confidence_score * 100, 0) }}%</td><td data-label="Missing">{{ implode(', ', $item->missing_fields ?? []) ?: '-' }}</td><td data-label="Status"><span class="badge badge-gold">{{ $item->status }}</span></td><td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.extracted.show', $item) }}">Detail</a></td></tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada extracted data.</td></tr>@endforelse</tbody></table><div style="margin-top:1rem;">{{ $items->links() }}</div></div></div>
@endsection
