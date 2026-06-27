@extends('layouts.app')

@section('title', $title . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Reports" title="{{ $title }}" description="Data periode {{ $startDate }} sampai {{ $endDate }}. Maksimal 500 row ditampilkan untuk menjaga performa dashboard.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.reports.index', ['start_date' => $startDate, 'end_date' => $endDate]) }}"><i data-lucide="arrow-left"></i> Kembali</a><a class="button button-primary" href="{{ route('admin.reports.export', ['type' => $type, 'start_date' => $startDate, 'end_date' => $endDate]) }}"><i data-lucide="download"></i> Export CSV</a></x-slot>
    </x-ui.page-header>
    <div class="card" style="margin-bottom:1rem;"><div class="card-body"><form method="GET" class="grid grid-3"><input class="input" type="date" name="start_date" value="{{ $startDate }}"><input class="input" type="date" name="end_date" value="{{ $endDate }}"><button class="button button-secondary" type="submit"><i data-lucide="filter"></i> Terapkan</button></form></div></div>
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr>@foreach($headers as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>@forelse($rows as $row)<tr>@foreach(array_keys($headers) as $key)<td data-label="{{ $headers[$key] }}">{{ is_numeric($row[$key] ?? null) && str_contains($key, 'revenue') || $key === 'discount' ? number_format((float) ($row[$key] ?? 0),0,',','.') : ($row[$key] ?? '-') }}</td>@endforeach</tr>@empty<tr><td colspan="{{ count($headers) }}" style="text-align:center;padding:2rem;">Tidak ada data pada periode ini.</td></tr>@endforelse</tbody></table></div></div>
@endsection
