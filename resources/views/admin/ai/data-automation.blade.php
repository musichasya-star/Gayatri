@extends('layouts.app')

@section('title', 'AI Data Automation - Gayatri CRM')

@section('content')
    <x-ui.page-header
        eyebrow="AI Management"
        title="AI Data Automation"
        description="Review data yang diekstrak AI, atur approval, dan pastikan automation tetap aman untuk customer Gayatri."
    >
        <x-slot name="actions">
            <button class="button button-primary" type="button"><i data-lucide="plus"></i> Buat Rule</button>
        </x-slot>
    </x-ui.page-header>

    <section class="grid grid-4">
        <x-ui.stat-card icon="workflow" label="Rule Aktif" value="8" note="2 booking perlu approval" />
        <x-ui.stat-card icon="database-zap" label="Data Terekstrak" value="146" note="Hari ini 18 data baru" />
        <x-ui.stat-card icon="clipboard-check" label="Pending Approval" value="12" note="Butuh review admin" />
        <x-ui.stat-card icon="shield-alert" label="Guardrail Hit" value="5" note="Medis dan komplain" />
    </section>

    <section class="grid grid-2" style="margin-top: 1rem;">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Pending Approval</h2>
                <table class="table-card">
                    <thead>
                        <tr><th>Customer</th><th>Action</th><th>Confidence</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <tr><td data-label="Customer">Bunda Rina</td><td data-label="Action">Create Booking Draft</td><td data-label="Confidence">91%</td><td data-label="Status"><span class="badge badge-gold">Pending</span></td></tr>
                        <tr><td data-label="Customer">Bunda Sari</td><td data-label="Action">Update Customer</td><td data-label="Confidence">84%</td><td data-label="Status"><span class="badge badge-brown">Review</span></td></tr>
                        <tr><td data-label="Customer">Bunda Novi</td><td data-label="Action">Create Follow-Up</td><td data-label="Confidence">88%</td><td data-label="Status"><span class="badge badge-green">Safe</span></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">Test Automation</h2>
                <textarea class="input" rows="7" placeholder="Contoh: Halo saya Rina, mau booking Baby Spa besok jam 10 di cabang Gayatri."></textarea>
                <button class="button button-primary" type="button" style="width: 100%; margin-top: .8rem;"><i data-lucide="sparkles"></i> Jalankan Simulasi</button>
                <div class="soft-list" style="margin-top: .9rem;">
                    <div class="soft-list-item"><span>Intent</span><span class="badge badge-gold">booking_request</span></div>
                    <div class="soft-list-item"><span>Mode</span><span class="badge badge-brown">need_confirmation</span></div>
                    <div class="soft-list-item"><span>Missing field</span><strong>Nama bayi</strong></div>
                </div>
            </div>
        </div>
    </section>
@endsection
