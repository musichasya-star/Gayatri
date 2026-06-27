@extends('layouts.app')

@section('title', 'Pending Approval - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="AI Data Automation" title="Pending Approval" description="Setujui atau tolak aksi AI yang membutuhkan konfirmasi manusia." />
    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body"><table class="table-card"><thead><tr><th>Waktu</th><th>Customer</th><th>Target</th><th>Action</th><th>Mode</th><th>Status</th><th>Aksi</th></tr></thead><tbody>@forelse($approvals as $approval)<tr><td data-label="Waktu">{{ $approval->created_at->format('d M Y H:i') }}</td><td data-label="Customer">{{ $approval->customer?->name ?: '-' }}</td><td data-label="Target">{{ $approval->target_entity }}</td><td data-label="Action">{{ $approval->action }}</td><td data-label="Mode"><span class="badge badge-brown">{{ $approval->mode }}</span></td><td data-label="Status"><span class="badge {{ $approval->status === 'pending' ? 'badge-gold' : ($approval->status === 'approved' ? 'badge-green' : 'badge-red') }}">{{ $approval->status }}</span></td><td data-label="Aksi"><a class="button button-secondary" href="{{ route('admin.ai.data-automation.approvals.show', $approval) }}">Review</a></td></tr>@empty<tr><td colspan="7" style="text-align:center;padding:2rem;">Belum ada approval.</td></tr>@endforelse</tbody></table><div style="margin-top:1rem;">{{ $approvals->links() }}</div></div></div>
@endsection
