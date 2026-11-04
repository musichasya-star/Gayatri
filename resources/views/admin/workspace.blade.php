@extends('layouts.app')
@section('title','Ruang Kerja Gayatri')
@section('content')
@include('partials.admin-workbench-assets')
<x-ui.page-header eyebrow="Gayatri CRM" title="Ruang Kerja {{ auth()->user()->name }}" description="Akses pekerjaan sesuai peran dan penugasan Anda." />
<section id="notifikasi-operasional" class="card"><div class="card-body"><h2>WhatsApp Inbox</h2><p>{{ $count }} percakapan dapat diakses sesuai peran Anda.</p><a class="button button-primary" href="{{ route('admin.inbox') }}">Buka Inbox</a></div></section>
@endsection
