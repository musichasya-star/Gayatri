@extends('layouts.app')

@section('title', 'Settings - Gayatri CRM')

@section('content')
@include('partials.admin-workbench-assets')
    <x-ui.page-header eyebrow="Settings" title="AI Provider & WhatsApp Gateway" description="Atur provider AI dan koneksi WhatsApp Baileys atau WAHA dari dashboard admin.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.whatsapp.session') }}"><i data-lucide="qr-code"></i> WhatsApp Gateway</a></x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <nav class="ops-nav" data-record-nav aria-label="Bagian pengaturan"><a href="#provider-ai">Provider AI</a><a href="#provider-whatsapp">Gateway WhatsApp</a><a href="{{ route('admin.whatsapp.monitor') }}">Monitoring & antrean</a></nav>
    <section class="wb-settings">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title" id="provider-ai">Provider AI</h2><p class="wb-scope">Pengaturan provider dipisahkan dari kendali balasan otomatis. Atur bahasa dan layanan di <a href="{{ route('admin.ai.auto-replies.index') }}">Balasan Otomatis</a>; periksa kualitas melalui simulator.</p>
                <p class="muted" style="margin-bottom:1rem;">Pilih provider dan model AI. API key tidak ditampilkan ulang; isi hanya jika ingin mengganti.</p>
                <form data-guard-unsaved method="POST" action="{{ route('admin.settings.ai.update') }}" class="grid grid-2">
                    @csrf
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Provider</span>
                        <select class="input" name="provider" data-ai-provider required>
                            @foreach($aiProviders as $provider)
                                <option value="{{ $provider }}" @selected(old('provider', $settings['ai.provider']) === $provider)>{{ ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Model</span>
                        <select class="input" name="model" data-ai-model required>
                            @foreach($aiModels as $provider => $models)
                                <optgroup label="{{ ucfirst($provider) }}">
                                    @foreach($models as $model)
                                        <option value="{{ $model }}" @selected(old('model', $settings['ai.model']) === $model)>{{ in_array($model, ['gpt-6-luna', 'openai/gpt-6-luna'], true) ? 'GPT-6-Luna · '.$model : $model }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label data-ai-key-field style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">API Key</span>
                        <input class="input" type="password" name="api_key" placeholder="{{ $settings['ai.api_key_set'] ? 'API key sudah tersimpan. Isi untuk mengganti.' : 'Masukkan API key provider AI' }}">
                    </label>
<details class="ops-section" style="grid-column:1/-1"><summary>Pengaturan lanjutan · variasi jawaban & batas keluaran</summary><div class="ops-section-body ops-fields">                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Temperature</span>
                        <input class="input" type="number" step="0.1" min="0" max="2" name="temperature" value="{{ old('temperature', $settings['ai.temperature']) }}" required>
                        <small class="muted">Untuk GPT-6-Luna, pengaturan Temperature tidak diterapkan.</small>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Max Tokens</span>
                        <input class="input" type="number" min="100" max="8000" name="max_tokens" value="{{ old('max_tokens', $settings['ai.max_tokens']) }}" required>
                    </label>
</div></details>                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan AI</button></div>
                </form>
                <form method="POST" action="{{ route('admin.settings.ai.test') }}" style="margin-top:1rem;display:flex;justify-content:flex-end;">
                    @csrf
                    <button class="button button-secondary" type="submit"><i data-lucide="activity"></i> Test Connection AI</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                @php($gatewayProvider = in_array(old('gateway_provider'),['baileys','waha'],true) ? old('gateway_provider') : $gatewayProvider)
                @php($gateway = $gatewaySettings[$gatewayProvider])
                <h2 class="section-title" id="provider-whatsapp">WhatsApp Gateway</h2>
                <p class="muted" style="margin-bottom:1rem;">Koneksi ini dipakai untuk session, inbox, balasan AI, campaign, reminder, dan feedback. Service Baileys lokal berjalan di port 3001.</p>
                <form data-guard-unsaved method="POST" action="{{ route('admin.settings.whatsapp.update') }}" class="grid grid-2" id="whatsapp-settings">
                    @csrf
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Gateway</span>
                        <select class="input" name="gateway_provider" id="gateway-provider" required>
                            <option value="baileys" @selected($gatewayProvider === 'baileys')>Baileys</option>
                            <option value="waha" @selected($gatewayProvider === 'waha')>WAHA (legacy)</option>
                        </select>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Base URL</span>
                        <input class="input" type="url" name="base_url" value="{{ old('base_url',$gateway['base_url']) }}" placeholder="http://127.0.0.1:3001" required>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Webhook Base URL (Opsional)</span>
                        <input class="input" type="url" name="webhook_base_url" value="{{ old('webhook_base_url',$gateway['webhook_base_url']) }}" placeholder="http://127.0.0.1:8000">
                        <small class="muted">Alamat aplikasi Gayatri yang bisa diakses service gateway. Sertakan subfolder jika memakai XAMPP, misalnya http://localhost/Gayatri/public.</small>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">API Key</span>
                        <input class="input" type="password" name="api_key" autocomplete="new-password" placeholder="{{ $gateway['api_key_set'] ? 'API key sudah tersimpan. Isi untuk mengganti.' : 'Masukkan API key gateway' }}">
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Default Session</span>
                        <input class="input" name="default_session" value="{{ old('default_session',$gateway['default_session']) }}" pattern="[a-zA-Z0-9_-]+" required>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Timeout Seconds</span>
                        <input class="input" type="number" min="3" max="120" name="timeout" value="{{ old('timeout',$gateway['timeout']) }}" required>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Webhook Secret</span>
                        <input class="input" type="password" name="webhook_secret" autocomplete="new-password" placeholder="{{ $gateway['webhook_secret_set'] ? 'Webhook secret sudah tersimpan. Isi untuk mengganti.' : 'Masukkan webhook secret service Baileys' }}">
                    </label>
                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Gateway</button></div>
                </form>
                <form method="POST" action="{{ route('admin.settings.whatsapp.test') }}" style="margin-top:1rem;display:flex;justify-content:flex-end;">
                    @csrf
                    <button class="button button-secondary" type="submit"><i data-lucide="activity"></i> Test Connection</button>
                </form>
            </div>
        </div>
    </section>
    <script>
        const gatewaySettings = @json($gatewaySettings);
        document.getElementById('gateway-provider').addEventListener('change', (event) => {
            const form = document.getElementById('whatsapp-settings');
            const selected = gatewaySettings[event.target.value];
            for (const name of ['base_url', 'default_session', 'webhook_base_url', 'timeout']) {
                form.elements[name].value = selected[name] ?? '';
            }
            for (const name of ['api_key', 'webhook_secret']) {
                form.elements[name].value = '';
                form.elements[name].placeholder = selected[name + '_set'] ? 'Sudah tersimpan. Isi untuk mengganti.' : 'Belum dikonfigurasi pada gateway ini.';
            }
        });
    </script>
@endsection
