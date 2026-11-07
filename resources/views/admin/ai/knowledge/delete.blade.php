@extends('layouts.app')
@section('title','Konfirmasi Hapus Knowledge - Gayatri CRM')
@section('content')
@include('partials.admin-workbench-assets')
@push('styles')<link rel="stylesheet" href="{{ asset('css/knowledge-delete.css').'?v='.config('gayatri_frontend.knowledge_delete_version') }}">@endpush
<x-ui.page-header eyebrow="Knowledge Base" title="Konfirmasi hapus materi" description="Periksa materi yang dipilih sebelum melanjutkan." />
@if($errors->any())<div class="card" role="alert" style="padding:1rem;"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<section class="card knowledge-delete-page"><div class="card-body">
<h2 class="knowledge-delete-name">{{ $knowledge->title }}</h2>
<p>Materi dan potongan informasinya akan dihapus permanen. Riwayat percakapan dan log AI tetap tersimpan.</p>
<form method="POST" action="{{ route('admin.ai.knowledge.destroy',$knowledge) }}">
@csrf @method('DELETE') @include('partials.admin-review-fields',['reviewModule'=>'knowledge'])
<input type="hidden" name="revision" value="{{ $knowledge->revision }}"><input type="hidden" name="confirmed" value="1">
<div class="knowledge-delete-actions"><a class="button button-secondary" href="{{ \App\Support\AdminReviewContext::back(request(),'knowledge') }}">Batal</a><button type="submit" class="button button-primary knowledge-delete-confirm">Hapus permanen</button></div>
</form>
</div></section>
@endsection
