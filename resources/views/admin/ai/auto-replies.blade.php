@extends('layouts.app')

@section('title', 'Balasan Otomatis - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    @if(! app(\App\Services\AI\AiInteractionPolicy::class)->allowsLegacyAutomation())
        @include('admin.ai.partials.controls')
    @else
    @include('admin.ai.partials.information-mode')
    <x-ui.page-header eyebrow="AI Management" title="Balasan Otomatis" description="Sesuaikan cara asisten menyapa, menjawab, dan menutup percakapan WhatsApp.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.ai.personas.index') }}"><i data-lucide="bot"></i> Persona AI</a>
            <button class="button button-primary" type="submit" form="auto-reply-form"><i data-lucide="save"></i> Simpan Pengaturan</button>
        </x-slot>
    </x-ui.page-header>

    @if(session('status'))
        <div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;" role="status">{{ session('status') }}</div></div>
    @endif
    @if($errors->any())
        <div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
    @endif

    <div class="card" style="margin-bottom:1rem;"><div class="card-body" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;justify-content:space-between;">
        <div><span class="badge {{ $settings['enabled'] ? 'badge-green' : 'badge-brown' }}">{{ $settings['enabled'] ? 'Balasan AI aktif' : 'Balasan AI nonaktif' }}</span><p class="muted" style="margin-top:.5rem;">Persona aktif: <strong>{{ $activePersona?->name ?: 'Belum dipilih' }}</strong>. Percakapan yang diambil alih admin tetap ditangani manual.</p></div>
        <a class="button button-ghost" href="{{ route('admin.ai.knowledge.index') }}"><i data-lucide="book-open"></i> Kelola Informasi Layanan</a>
    </div></div>

    <form id="auto-reply-form" method="POST" action="{{ route('admin.ai.auto-replies.update') }}">
        @csrf
        <section class="card" style="margin-bottom:1rem;"><div class="card-body">
            <h2 class="section-title">Layanan pada Asisten</h2>
            <p class="muted">Aktifkan informasi otomatis per layanan. Pengaturan ini tidak membuka pemesanan publik atau mengubah transaksi. FAQ dikelola melalui Knowledge Base sesuai cakupan layanan.</p>
            @foreach(\App\Support\AiDomain::MENU as $domain)
                <input type="hidden" name="service_domains[{{ $domain }}]" value="0">
                <label style="display:inline-flex;gap:.5rem;margin:.75rem 1rem .5rem 0;"><input type="checkbox" name="service_domains[{{ $domain }}]" value="1" @checked(old('service_domains.'.$domain, $settings['service_domains'][$domain] ?? true))>{{ \App\Support\AiDomain::LABELS[$domain] }}</label>
            @endforeach
            <p class="muted">Template, tanda tangan, instruksi provider dan rule global lama tetap tersimpan untuk peninjauan. Alur informasi/web memakai katalog, panduan sistem dan knowledge terbit; field lama tidak dapat menggantinya.</p>
        </div></section>
        <div class="grid grid-2" style="align-items:start;">
            <section class="card"><div class="card-body">
                <h2 class="section-title">Identitas & Gaya Balasan</h2>
                <p class="muted" style="margin-bottom:1rem;">Sakelar balasan aktif berlaku sekarang. Pengaturan gaya lama tetap tersimpan; isi jawaban aktif dikelola melalui Knowledge Base per layanan.</p>
                <input type="hidden" name="enabled" value="0">
                <label style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', $settings['enabled']))> Aktifkan balasan AI otomatis</label>
                <div class="grid grid-2">
                    <label><span class="reply-field-label">Nama Asisten</span><input class="input" name="assistant_name" maxlength="80" value="{{ old('assistant_name', $settings['assistant_name']) }}" required></label>
                    <label><span class="reply-field-label">Nama Bisnis</span><input class="input" name="business_name" maxlength="80" value="{{ old('business_name', $settings['business_name']) }}" required></label>
                    <label><span class="reply-field-label">Panggilan Customer</span><input class="input" name="salutation" maxlength="40" value="{{ old('salutation', $settings['salutation']) }}" placeholder="Bunda, Kak, atau Ibu" required></label>
                    <label><span class="reply-field-label">Gaya Bahasa</span><select class="input" name="tone" required>@foreach($tones as $value => $label)<option value="{{ $value }}" @selected(old('tone', $settings['tone']) === $value)>{{ $label }}</option>@endforeach</select></label>
                </div>
                <input type="hidden" name="use_emoji" value="0">
                <label style="display:flex;align-items:center;gap:.6rem;margin:1rem 0;"><input type="checkbox" name="use_emoji" value="1" @checked(old('use_emoji', $settings['use_emoji']))> Izinkan emoji dalam balasan</label>
                <label><span class="reply-field-label">Instruksi Tambahan AI</span><textarea class="input" name="instructions" rows="4" maxlength="2000" placeholder="Contoh: Jawab dengan sopan dan singkat. Tawarkan bantuan lanjutan tanpa memaksa.">{{ old('instructions', $settings['instructions']) }}</textarea></label>
                <p class="muted" style="margin-top:.5rem;">Instruksi ini tersimpan untuk peninjauan. Gunakan Knowledge Base untuk mengatur FAQ publik pada alur informasi dan pemesanan web.</p>
                <label style="display:block;margin-top:1rem;"><span class="reply-field-label">Penutup / Tanda Tangan</span><textarea class="input" name="signature" rows="2" maxlength="500" placeholder="Contoh: Salam hangat, {nama_asisten} — {nama_bisnis}">{{ old('signature', $settings['signature']) }}</textarea></label>
            </div></section>

            <section class="card"><div class="card-body">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.75rem;"><h2 class="section-title" style="margin-bottom:0;">Template Jawaban</h2><button class="button button-ghost" type="button" id="fill-reply-examples">Isi Contoh</button></div>
                <p class="muted" style="margin-bottom:1rem;">Kosongkan kolom untuk memakai balasan bawaan. Tombol Isi Contoh hanya mengisi kolom yang masih kosong.</p>
                @foreach([
                    'greeting' => ['Sapaan Awal', 'Halo {sapaan} {nama_customer}, saya {nama_asisten}. Ada yang bisa dibantu?'],
                    'thanks' => ['Balasan Terima Kasih', 'Sama-sama {sapaan}. Terima kasih sudah menghubungi {nama_bisnis}.'],
                    'fallback' => ['Saat Informasi Belum Tersedia', 'Maaf {sapaan}, informasinya belum tersedia. Kami bantu teruskan ke admin.'],
                    'error' => ['Saat Sistem Mengalami Kendala', 'Maaf {sapaan}, sistem sedang mengalami kendala. Silakan tunggu bantuan admin.'],
                ] as $key => [$label, $placeholder])
                    <label style="display:block;margin-bottom:1rem;"><span class="reply-field-label">{{ $label }}</span><textarea class="input" name="{{ $key }}" rows="3" maxlength="2000" placeholder="{{ $placeholder }}">{{ old($key, $settings[$key]) }}</textarea></label>
                @endforeach
                <div class="reply-variable-help"><strong>Variabel yang tersedia</strong><p style="margin-top:.5rem;"><code>{sapaan}</code> <code>{nama_customer}</code> <code>{nama_bisnis}</code> <code>{nama_asisten}</code></p><p class="muted" style="margin-top:.5rem;">Variabel otomatis diganti saat membalas. Jika nama customer belum diketahui, sistem memakai nama kontak yang tersimpan.</p></div>
            </div></section>
        </div>

        <section class="card" style="margin-top:1rem;"><div class="card-body">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:.75rem;"><h2 class="section-title" style="margin-bottom:0;">Balasan Berdasarkan Kata Kunci</h2><button class="button button-secondary" type="button" id="add-reply-rule"><i data-lucide="plus"></i> Tambah Aturan</button></div>
            <p class="muted" style="margin-bottom:1rem;">Maksimal 20 aturan. Prioritas dengan angka terkecil dijalankan lebih dulu; jika sama, urutan daftar dipakai. Aturan tidak menggantikan percakapan booking yang sedang berjalan maupun penanganan medis dan komplain.</p>
            @php($rules = array_values((array) old('rules', $settings['rules'])))
            <div id="reply-rules" style="display:grid;gap:1rem;">
                @foreach($rules as $index => $rule)
                    <div class="reply-rule" data-reply-rule>
                        <div class="grid grid-2">
                            <label><span class="reply-field-label">Nama Aturan</span><input class="input" name="rules[{{ $index }}][name]" value="{{ $rule['name'] }}" maxlength="100" required></label>
                            <label><span class="reply-field-label">Kata Kunci (pisahkan koma)</span><input class="input" name="rules[{{ $index }}][keywords]" value="{{ is_array($rule['keywords']) ? implode(', ', $rule['keywords']) : $rule['keywords'] }}" maxlength="500" required></label>
                            <label><span class="reply-field-label">Cara Mencocokkan</span><select class="input" name="rules[{{ $index }}][match_type]"><option value="exact" @selected($rule['match_type'] === 'exact')>Pesan sama persis</option><option value="contains" @selected($rule['match_type'] === 'contains')>Pesan mengandung kata kunci</option></select></label>
                            <label><span class="reply-field-label">Prioritas (0–100)</span><input class="input" type="number" name="rules[{{ $index }}][priority]" min="0" max="100" value="{{ $rule['priority'] }}" required></label>
                            <label style="grid-column:1/-1;"><span class="reply-field-label">Isi Balasan</span><textarea class="input" name="rules[{{ $index }}][reply]" rows="3" maxlength="1500" required>{{ $rule['reply'] }}</textarea></label>
                        </div>
                        <input type="hidden" name="rules[{{ $index }}][is_active]" value="0">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-top:.75rem;"><label style="display:flex;gap:.5rem;align-items:center;"><input type="checkbox" name="rules[{{ $index }}][is_active]" value="1" @checked($rule['is_active'])> Aturan aktif</label><button class="button button-ghost" type="button" data-remove-rule>Hapus Aturan</button></div>
                    </div>
                @endforeach
            </div>
            <p class="muted" id="no-reply-rules" @if(count($rules)) hidden @endif style="padding:1rem 0;">Belum ada aturan khusus. Tambahkan misalnya aturan “Metode pembayaran” dengan kata kunci “cara bayar, metode pembayaran”.</p>
        </div></section>

        <section class="card" style="margin-top:1rem;" id="reply-preview"><div class="card-body">
            <h2 class="section-title">Coba Balasan Sebelum Disimpan</h2>
            <p class="muted" style="margin-bottom:1rem;">Uji pesan baru tanpa riwayat percakapan. Alur informasi/web memakai pengaturan layanan yang sudah disimpan, katalog aktif dan knowledge terbit. Pratinjau tidak menyimpan perubahan atau mengirim WhatsApp. Gunakan Simulator untuk menguji beberapa giliran.</p>
            <div class="grid grid-2">
                <label><span class="reply-field-label">Nama Customer Contoh</span><input class="input" name="preview_customer" maxlength="100" value="{{ $previewCustomer }}"></label>
                <label><span class="reply-field-label">Pesan Customer Contoh</span><input class="input" name="preview_message" maxlength="2000" value="{{ $previewMessage }}" placeholder="Halo, terima kasih, atau kata kunci aturan"></label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;margin-top:1rem;">
                <button class="button button-secondary" type="submit" formaction="{{ route('admin.ai.auto-replies.preview') }}"><i data-lucide="eye"></i> Lihat Pratinjau</button>
                <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Pengaturan</button>
            </div>
            @if($preview)
                <div class="reply-preview-result" role="status">
                    <strong>Pratinjau Balasan</strong>
                    <p class="muted" style="margin:.5rem 0;">{{ $preview['reply_source'] === 'custom_rule' ? 'Aturan: '.$preview['reply_rule'] : 'Jawaban lokal / template' }} · Belum disimpan atau dikirim</p>
                    @if(!$settings['enabled'])<p style="margin-bottom:.75rem;">Balasan otomatis sedang nonaktif. Contoh di bawah tidak akan dikirim otomatis.</p>@endif
                    <div style="white-space:pre-wrap;overflow-wrap:anywhere;">{{ $preview['reply'] }}</div>
                </div>
            @endif
        </div></section>
    </form>

    <template id="reply-rule-template">
        <div class="reply-rule" data-reply-rule>
            <div class="grid grid-2">
                <label><span class="reply-field-label">Nama Aturan</span><input class="input" name="rules[__INDEX__][name]" maxlength="100" placeholder="Metode pembayaran" required></label>
                <label><span class="reply-field-label">Kata Kunci (pisahkan koma)</span><input class="input" name="rules[__INDEX__][keywords]" maxlength="500" placeholder="cara bayar, metode pembayaran" required></label>
                <label><span class="reply-field-label">Cara Mencocokkan</span><select class="input" name="rules[__INDEX__][match_type]"><option value="exact">Pesan sama persis</option><option value="contains">Pesan mengandung kata kunci</option></select></label>
                <label><span class="reply-field-label">Prioritas (0–100)</span><input class="input" type="number" name="rules[__INDEX__][priority]" value="10" min="0" max="100" required></label>
                <label style="grid-column:1/-1;"><span class="reply-field-label">Isi Balasan</span><textarea class="input" name="rules[__INDEX__][reply]" rows="3" maxlength="1500" placeholder="Contoh: {sapaan}, silakan hubungi admin untuk pilihan pembayaran yang tersedia." required></textarea></label>
            </div>
            <input type="hidden" name="rules[__INDEX__][is_active]" value="0">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;margin-top:.75rem;"><label style="display:flex;gap:.5rem;align-items:center;"><input type="checkbox" name="rules[__INDEX__][is_active]" value="1" checked> Aturan aktif</label><button class="button button-ghost" type="button" data-remove-rule>Hapus Aturan</button></div>
        </div>
    </template>
    <style>
        .reply-field-label{display:block;margin-bottom:.4rem;font-weight:700;color:#705b4d}
        .reply-rule,.reply-variable-help{padding:1rem;border:1px solid rgba(157,127,102,.2);border-radius:1rem;background:rgba(157,127,102,.035);min-width:0}
        .reply-variable-help code{display:inline-block;margin:.2rem;padding:.25rem .4rem;background:#f1e8d7;border-radius:.3rem;font-size:.85rem}
        .reply-preview-result{margin-top:1rem;padding:1.25rem;background:#f2f6ed;border:1px solid #c8d7b8;border-radius:1rem}
    </style>
    <script>
        (() => {
            const form = document.getElementById('auto-reply-form');
            const rules = document.getElementById('reply-rules');
            const empty = document.getElementById('no-reply-rules');
            const add = document.getElementById('add-reply-rule');
            let nextIndex = @json(count($rules) ? max(array_keys($rules)) + 1 : 0);
            const updateCount = () => {
                const count = rules.querySelectorAll('[data-reply-rule]').length;
                empty.hidden = count > 0;
                add.disabled = count >= 20;
            };
            add.addEventListener('click', () => {
                if (rules.querySelectorAll('[data-reply-rule]').length >= 20) return;
                const template = document.getElementById('reply-rule-template').innerHTML.replaceAll('__INDEX__', String(nextIndex++));
                rules.insertAdjacentHTML('beforeend', template);
                updateCount();
                rules.lastElementChild.querySelector('input').focus();
            });
            rules.addEventListener('click', (event) => {
                const remove = event.target.closest('[data-remove-rule]');
                if (remove) { remove.closest('[data-reply-rule]').remove(); updateCount(); }
            });
            document.getElementById('fill-reply-examples').addEventListener('click', () => {
                const examples = {
                    greeting: 'Halo {sapaan} {nama_customer}, saya {nama_asisten}. Terima kasih sudah menghubungi {nama_bisnis}. Ada yang bisa kami bantu? 😊',
                    thanks: 'Sama-sama {sapaan} {nama_customer}. Senang bisa membantu. Sampai bertemu di {nama_bisnis}! 🌷',
                    fallback: 'Mohon maaf {sapaan}, informasi tersebut belum tersedia. Kami bantu teruskan ke admin {nama_bisnis} agar mendapat jawaban yang tepat.',
                    error: 'Mohon maaf {sapaan}, sistem sedang mengalami kendala. Admin {nama_bisnis} akan membantu percakapan ini.',
                };
                for (const [key, value] of Object.entries(examples)) {
                    if (!form.elements[key].value.trim()) form.elements[key].value = value;
                }
            });
            updateCount();
        })();
    </script>
    @endif
@endsection
