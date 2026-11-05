@extends('layouts.app')

@section('title', 'Preview Landing Page - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Pratinjau beranda" title="Pratinjau {{ ucfirst($device) }}" description="Tampilan konten tersimpan. Pratinjau hanya dapat dibuka oleh staf yang berwenang.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.landing.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @include('admin.landing.readiness')
    @php($width = ['desktop' => '100%', 'tablet' => '820px', 'mobile' => '390px'][$device])
    <div style="display:flex;justify-content:center;">
        <iframe title="Landing preview" src="{{ route('landing.show', ['preview' => 1]) }}" style="width:{{ $width }};max-width:100%;height:80vh;border:1px solid rgba(var(--g-line-rgb), .8);border-radius:1.5rem;box-shadow:var(--shadow-soft);background:#fff;"></iframe>
    </div>
@endsection
