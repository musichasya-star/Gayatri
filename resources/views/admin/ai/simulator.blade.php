@extends('layouts.app')

@section('title', 'AI Simulator - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    @include('admin.ai.partials.information-mode')
    <x-ui.page-header eyebrow="AI Management" title="AI Chat Simulator" description="Uji jawaban AI tanpa mengirim pesan ke WhatsApp customer." />
    @if($errors->any())<p class="card" role="alert" style="padding:1rem;">{{ $errors->first() }}</p>@endif
    <div class="wb-simulator">
        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('admin.ai.simulator.test') }}" class="grid">
                @csrf
                @if($simulationHandoff ?? false)<p class="wb-scope">Simulasi sedang diserahkan ke admin. Buka opsi percakapan untuk melanjutkan AI pada pesan baru.</p>@endif
                <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Pertanyaan Customer</span><textarea class="input" rows="4" name="message" required>{{ old('message', $message ?? '') }}</textarea></label>
                <button class="button button-primary" type="submit"><i data-lucide="bot"></i> Uji jawaban</button>
<details class="ops-section"><summary>Opsi percakapan & data pengujian</summary><div class="ops-section-body">
                <label><input type="checkbox" name="reset_context" value="1"> Mulai percakapan baru (hapus konteks simulasi sebelumnya)</label>
                @if($simulationHandoff ?? false)<p role="status" class="muted">Percakapan simulasi sudah diserahkan ke admin. AI berhenti sampai dilanjutkan.</p><label><input type="checkbox" name="resume_context" value="1"> Lanjutkan AI pada simulator · pesan baru</label>@endif
                @if(app()->environment('local','baileys-local','testing'))
                    <label><input type="checkbox" name="include_demo" value="1" @checked($includeDemo ?? false)> Sertakan FAQ demo (khusus uji lokal)</label>
                    <small class="muted">FAQ berlabel DEMO tidak dipakai pada balasan WhatsApp pelanggan.</small>
                    <label>Identitas pengirim untuk uji status DEMO<select class="input" name="demo_profile_id"><option value="">Tanpa identitas (hasil privat diblokir)</option>@foreach($demoProfiles as $profile)<option value="{{ $profile->id }}" @selected(($demoProfileId ?? 0)==$profile->id)>{{ $profile->label }} · {{ $profile->phone }}</option>@endforeach</select></label>
                    <details><summary>Nomor dan kode transaksi contoh</summary><p class="muted">Centang FAQ demo dan pilih profil di atas. Kode milik profil lain harus ditolak.</p>@foreach($demoProfiles as $profile)<p><strong>{{ $profile->label }}</strong> · {{ $profile->phone }}<br>{{ implode(' · ', json_decode($profile->examples,true) ?: []) }}</p>@endforeach</details>
                @endif
                <p class="muted">Untuk mencoba menu, kirim “halo”, lalu angka pilihan. Pengecekan status simulator terbatas pada profil dan transaksi DEMO lokal; data pelanggan asli tidak dapat dipilih.</p>
                @if(app(\App\Services\AI\AiInteractionPolicy::class)->allowsLegacyAutomation())<label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Persona</span><select class="input" name="persona_id"><option value="">Persona aktif</option>@foreach($personas as $persona)<option value="{{ $persona->id }}" @selected(($personaId ?? null)==$persona->id)>{{ $persona->name }}{{ $persona->is_active ? ' (aktif)' : '' }}</option>@endforeach</select></label>@else<p class="muted">Gaya percakapan mengikuti <a href="{{ route('admin.ai.auto-replies.index') }}">Balasan Otomatis</a>. Uji ini tidak memakai provider AI berbayar.</p>@endif
                <details><summary>Contoh skenario percakapan</summary><div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.75rem;">@foreach(['Menu layanan'=>'Halo min','FAQ Spa'=>'Apa persiapan pijat spa?','FAQ Laktasi'=>'Apa persiapan konsultasi laktasi?','FAQ Rental'=>'Bagaimana kelengkapan alat rental?','FAQ Laundry'=>'Bagaimana pewangi laundry?','Pemesanan web'=>'Mau sewa stroller','Status demo A'=>'Cek status BK-DEMO-C-SPA-A 080000000901','Uji bukan pemilik A'=>'Cek status BK-DEMO-C-SPA-B 080000000901','Bantuan admin'=>'Saya ingin bicara dengan admin'] as $label=>$sample)<button class="button button-ghost" type="button" data-simulator-message="{{ $sample }}">{{ $label }}</button>@endforeach</div><p class="muted">FAQ contoh membutuhkan centang demo. Status demo A membutuhkan profil A; contoh bukan pemilik harus ditolak.</p></details>
</div></details>
            </form>
        </div></div>
        <div class="card"><div class="card-body">
            <h2 class="section-title">Hasil Simulator</h2>
            @if ($result)
                <div class="wb-simulator-result">{{ $result['reply'] }}</div>
<details class="ops-section"><summary>Alasan, cakupan & sumber jawaban</summary><div class="ops-section-body">
                @if($result['routing'] ?? null)
                    <p class="muted"><strong>Layanan:</strong> {{ \App\Support\AiDomain::LABELS[$result['routing']['domain'] ?? 'general'] ?? 'Perlu klarifikasi' }} · <strong>Maksud:</strong> {{ $result['routing']['intent'] }} · {{ $result['routing']['reason_code'] }}</p>
                @endif
                <p><strong>Status:</strong> <span class="badge {{ $result['status'] === 'success' ? 'badge-green' : 'badge-red' }}">{{ $result['status'] }}</span></p>
                <p class="muted">{{ \App\Services\AI\AiTraceService::REASONS[$result['routing']['reason_code'] ?? ''] ?? 'Periksa sumber dan konteks jawaban di bawah.' }}</p>
                @if(count($result['routing']['answered_intents'] ?? []) > 1)<p class="muted"><strong>Kebutuhan yang dijawab:</strong> {{ implode(', ', array_map(fn($intent)=>\App\Services\AI\AiTraceService::INTENTS[$intent] ?? $intent, $result['routing']['answered_intents'])) }}</p>@endif
                @if ($result['fallback_reason'])<p><strong>Fallback:</strong> {{ $result['fallback_reason'] }}</p>@endif

                <p class="muted"><strong>Sources:</strong> {{ implode(', ', $result['sources']) ?: '-' }}</p>
                <p class="muted"><strong>AI Log ID:</strong> @if($result['log_id'])<a href="{{ route('admin.ai.logs.show',$result['log_id']) }}">{{ $result['log_id'] }} · Lihat alur</a>@else—@endif</p>
</div></details>
            @else
                <p class="muted">Masukkan contoh pertanyaan untuk melihat jawaban, sumber informasi, dan langkah percakapan berikutnya.</p>
            @endif
        </div></div>
    </div>
    @if($history ?? [])<details class="ops-section"><summary>Riwayat percakapan simulasi</summary><div class="ops-section-body"><p class="muted">Maksimum 10 giliran pada sesi ini. Mengganti profil atau memulai percakapan baru menghapus riwayat sesi.</p>@foreach($history as $turn)<article style="padding:1rem 0;border-top:1px solid #e4d9c4;"><strong>Pelanggan</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $turn['question'] }}</p><strong>Asisten</strong><p style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $turn['answer'] }}</p><small>{{ \App\Support\AiDomain::LABELS[$turn['domain'] ?? 'general'] ?? 'Belum dipilih' }} · {{ $turn['reason'] }}</small></article>@endforeach</div></details>@endif
    <script>document.querySelectorAll('[data-simulator-message]').forEach(button=>button.addEventListener('click',()=>{const field=document.querySelector('textarea[name="message"]');field.value=button.dataset.simulatorMessage;field.focus();}));</script>
@endsection
