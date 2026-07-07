@extends('layouts.app')

@section('title', 'Settings - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Settings" title="AI Provider & WAHA API" description="Atur provider AI, model yang dipakai, dan koneksi WAHA gateway dari dashboard admin.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.whatsapp.session') }}"><i data-lucide="qr-code"></i> WhatsApp Gateway</a></x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <section class="grid grid-2">
        <div class="card">
            <div class="card-body">
                <h2 class="section-title">AI Provider</h2>
                <p class="muted" style="margin-bottom:1rem;">Pilih provider dan model AI. API key tidak ditampilkan ulang; isi hanya jika ingin mengganti.</p>
                <form method="POST" action="{{ route('admin.settings.ai.update') }}" class="grid grid-2">
                    @csrf
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Provider</span>
                        <select class="input" name="provider" required>
                            @foreach($aiProviders as $provider)
                                <option value="{{ $provider }}" @selected(old('provider', $settings['ai.provider']) === $provider)>{{ ucfirst($provider) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Model</span>
                        <select class="input" name="model" required>
                            @foreach($aiModels as $provider => $models)
                                <optgroup label="{{ ucfirst($provider) }}">
                                    @foreach($models as $model)
                                        <option value="{{ $model }}" @selected(old('model', $settings['ai.model']) === $model)>{{ $model }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">API Key</span>
                        <input class="input" type="password" name="api_key" placeholder="{{ $settings['ai.api_key_set'] ? 'API key sudah tersimpan. Isi untuk mengganti.' : 'Masukkan API key provider AI' }}">
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Temperature</span>
                        <input class="input" type="number" step="0.1" min="0" max="2" name="temperature" value="{{ old('temperature', $settings['ai.temperature']) }}" required>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Max Tokens</span>
                        <input class="input" type="number" min="100" max="8000" name="max_tokens" value="{{ old('max_tokens', $settings['ai.max_tokens']) }}" required>
                    </label>
                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan AI</button></div>
                </form>
                <form method="POST" action="{{ route('admin.settings.ai.test') }}" style="margin-top:1rem;display:flex;justify-content:flex-end;">
                    @csrf
                    <button class="button button-secondary" type="submit"><i data-lucide="activity"></i> Test Connection AI</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h2 class="section-title">WAHA API</h2>
                <p class="muted" style="margin-bottom:1rem;">Base URL dan API key WAHA dipakai untuk WhatsApp session, inbox, campaign, reminder, dan feedback.</p>
                <form method="POST" action="{{ route('admin.settings.waha.update') }}" class="grid grid-2">
                    @csrf
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Base URL</span>
                        <input class="input" type="url" name="base_url" value="{{ old('base_url', $settings['waha.base_url']) }}" placeholder="http://localhost:3000" required>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Webhook Base URL (Opsional)</span>
                        <input class="input" type="url" name="webhook_base_url" value="{{ old('webhook_base_url', $settings['waha.webhook_base_url']) }}" placeholder="https://7digital-solution.web.id">
                        <small class="muted">Kosongkan jika WAHA berjalan di lokal. Isi URL publik agar WAHA eksternal bisa memanggil webhook.</small>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">API Key</span>
                        <input class="input" type="password" name="api_key" placeholder="{{ $settings['waha.api_key_set'] ? 'API key sudah tersimpan. Isi untuk mengganti.' : 'Masukkan X-Api-Key WAHA jika dipakai' }}">
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Default Session</span>
                        <input class="input" name="default_session" value="{{ old('default_session', $settings['waha.default_session']) }}" required>
                    </label>
                    <label>
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Timeout Seconds</span>
                        <input class="input" type="number" min="3" max="120" name="timeout" value="{{ old('timeout', $settings['waha.timeout']) }}" required>
                    </label>
                    <label style="grid-column:1/-1;">
                        <span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Webhook Secret</span>
                        <input class="input" type="password" name="webhook_secret" placeholder="{{ $settings['waha.webhook_secret_set'] ? 'Webhook secret sudah tersimpan. Isi untuk mengganti.' : 'Opsional untuk validasi webhook' }}">
                    </label>
                    <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;flex-wrap:wrap;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan WAHA</button></div>
                </form>
                <form method="POST" action="{{ route('admin.settings.waha.test') }}" style="margin-top:1rem;display:flex;justify-content:flex-end;">
                    @csrf
                    <button class="button button-secondary" type="submit"><i data-lucide="activity"></i> Test Connection</button>
                </form>
            </div>
        </div>
    </section>
@endsection
