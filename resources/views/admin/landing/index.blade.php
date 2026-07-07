@extends('layouts.app')

@section('title', 'Landing Page CMS - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Content Management" title="Landing Page CMS" description="Kelola konten landing page dari database: settings, section, item, media, preview, dan publish.">
        <x-slot name="actions">
            <a class="button button-secondary" href="{{ route('admin.landing.preview', ['device' => 'desktop']) }}" target="_blank"><i data-lucide="monitor"></i> Preview</a>
            <form method="POST" action="{{ route('admin.landing.publish') }}">@csrf<button class="button button-primary" type="submit"><i data-lucide="send"></i> Publish</button></form>
        </x-slot>
    </x-ui.page-header>

    @if(session('status'))<div class="card" style="margin-bottom:1rem;border-color:rgba(143,174,139,.55);"><div class="card-body" style="color:#3f6d3d;">{{ session('status') }}</div></div>@endif
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif

    <div class="grid grid-3" style="margin-bottom:1rem;">
        <div class="card"><div class="card-body"><p class="eyebrow">Status</p><h3 style="margin:.2rem 0;">{{ $page->is_published ? 'Published' : 'Draft' }}</h3><p class="muted">{{ $page->published_at?->format('d M Y H:i') ?: 'Belum dipublish' }}</p></div></div>
        <div class="card"><div class="card-body"><p class="eyebrow">Sections</p><h3 style="margin:.2rem 0;">{{ $page->sections->count() }}</h3><p class="muted">Aktif: {{ $page->sections->where('is_active', true)->count() }}</p></div></div>
        <div class="card"><div class="card-body"><p class="eyebrow">Preview</p><div style="display:flex;gap:.5rem;flex-wrap:wrap;"><a class="button button-secondary" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'desktop']) }}">Desktop</a><a class="button button-secondary" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'tablet']) }}">Tablet</a><a class="button button-secondary" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'mobile']) }}">Mobile</a></div></div></div>
    </div>

    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="padding-bottom:.8rem;">
            <div class="landing-tabs" role="tablist" aria-label="Landing Page CMS Tabs" style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <button class="button button-primary" type="button" data-landing-tab="settings"><i data-lucide="settings"></i> Settings</button>
                <button class="button button-secondary" type="button" data-landing-tab="booking-page"><i data-lucide="calendar-check"></i> Booking Page</button>
                <button class="button button-secondary" type="button" data-landing-tab="sections"><i data-lucide="layout-template"></i> Sections</button>
                <button class="button button-secondary" type="button" data-landing-tab="add-section"><i data-lucide="plus"></i> Tambah Section</button>
                <button class="button button-secondary" type="button" data-landing-tab="media"><i data-lucide="images"></i> Media Library</button>
                <button class="button button-secondary" type="button" data-landing-tab="preview"><i data-lucide="monitor-smartphone"></i> Preview & Publish</button>
            </div>
        </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="settings">
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <h3 style="margin-top:0;">Page, SEO & Theme Settings</h3>
            <form method="POST" action="{{ route('admin.landing.settings.update') }}" class="grid grid-3">
                @csrf
                <label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">Site Name</span><input class="input" name="site_name" value="{{ old('site_name', $page->site_name) }}" required></label>
                @foreach(['page_settings' => 'Page Settings', 'seo_settings' => 'SEO Settings', 'theme_settings' => 'Theme Settings'] as $group => $label)
                    @foreach(($page->{$group} ?: []) as $key => $value)
                        <label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">{{ $label }}: {{ str_replace('_', ' ', $key) }}</span><input class="input" name="{{ $group }}[{{ $key }}]" value="{{ is_array($value) ? json_encode($value) : $value }}"></label>
                    @endforeach
                @endforeach
                <div style="grid-column:1/-1;display:flex;justify-content:flex-end;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Settings</button></div>
            </form>
        </div>
    </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="booking-page" hidden>
    @php($bookingPage = data_get($page->page_settings ?? [], 'booking_page', []))
    @php($bookingDefaults = [
        'meta_title' => 'Booking Online - Gayatri Mom & Baby SPA',
        'meta_description' => 'Booking online Gayatri Mom & Baby SPA. Pilih layanan, tanggal, dan jam, lalu admin akan mengonfirmasi melalui WhatsApp.',
        'nav_back_label' => 'Kembali ke Landing Page',
        'eyebrow' => 'Booking Online',
        'headline' => 'Reservasi treatment lembut untuk Bunda dan si kecil.',
        'lead' => 'Pilih layanan, tanggal, dan jam favorit. Tim Gayatri akan mengecek slot lalu mengirim konfirmasi melalui WhatsApp.',
        'hero_image_url' => 'https://images.unsplash.com/photo-1544161515-4ab6ce6db874?auto=format&fit=crop&w=1200&q=80',
        'hero_badge_1_title' => 'Pending dulu',
        'hero_badge_1_text' => 'Request masuk CRM dengan status menunggu konfirmasi.',
        'hero_badge_2_title' => 'Bisa ubah jadwal',
        'hero_badge_2_text' => 'Selama admin belum approve, jadwal bisa diubah dari session ini.',
        'hero_badge_3_title' => 'Konfirmasi WA',
        'hero_badge_3_text' => 'Admin akan menghubungi nomor WhatsApp yang Bunda isi.',
        'status_badge' => 'Status Booking',
        'empty_status_title' => 'Belum Ada Request Aktif',
        'empty_status_text' => 'Isi form booking. Setelah submit, detail request akan muncul di sini selama session browser masih aktif.',
        'pending_status_title' => 'Menunggu Konfirmasi Admin',
        'processed_status_title' => 'Sudah Diproses Admin',
        'pending_note' => 'Bunda masih bisa mengubah layanan, tanggal, atau jam sebelum admin mengonfirmasi.',
        'processed_note' => 'Booking sudah diproses admin, perubahan jadwal perlu melalui WhatsApp/admin.',
        'step_1_title' => 'Isi nomor WhatsApp',
        'step_1_text' => 'Nomor ini disimpan ke CRM karena WAHA tidak selalu bisa mengambil nomor yang sesuai otomatis.',
        'step_2_title' => 'Pilih jadwal',
        'step_2_text' => 'Pilih layanan, cabang, tanggal, dan jam treatment.',
        'step_3_title' => 'Admin cek slot',
        'step_3_text' => 'Status awal pending_confirmation.',
        'step_4_title' => 'Konfirmasi',
        'step_4_text' => 'Admin menghubungi via WhatsApp yang Bunda isi.',
        'reschedule_eyebrow' => 'Ubah Jadwal',
        'reschedule_title' => 'Update request sebelum dikonfirmasi',
        'reschedule_button' => 'Simpan Perubahan Jadwal',
        'form_eyebrow' => 'Form Booking',
        'form_title' => 'Buat request booking baru',
        'submit_button' => 'Kirim Request Booking',
        'footer_note' => 'Gayatri Mom & Baby SPA akan menjaga data booking Bunda hanya untuk kebutuhan reservasi dan follow-up layanan.',
        'floating_1' => 'Private room',
        'floating_2' => 'Certified therapist',
        'floating_3' => 'Soft gold ambience',
    ])
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem;">
                <div>
                    <h3 style="margin:0;">Konten Halaman Booking</h3>
                    <p class="muted" style="margin:.35rem 0 0;">Atur teks dan visual untuk halaman publik <code>/booking</code>.</p>
                </div>
                <a class="button button-secondary" href="{{ route('landing.booking-page') }}" target="_blank"><i data-lucide="external-link"></i> Lihat /booking</a>
            </div>
            <form method="POST" action="{{ route('admin.landing.settings.update') }}" class="grid grid-3">
                @csrf
                <input type="hidden" name="site_name" value="{{ $page->site_name }}">
                @foreach($bookingDefaults as $key => $default)
                    @php($isLong = str_contains($key, 'text') || str_contains($key, 'description') || str_contains($key, 'lead') || str_contains($key, 'note') || $key === 'footer_note')
                    <label @if($isLong) style="grid-column:span 2;" @endif>
                        <span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">{{ str($key)->replace('_', ' ')->title() }}</span>
                        @if($isLong)
                            <textarea class="input" name="page_settings[booking_page][{{ $key }}]" rows="3">{{ old("page_settings.booking_page.{$key}", $bookingPage[$key] ?? $default) }}</textarea>
                        @else
                            <input class="input" name="page_settings[booking_page][{{ $key }}]" value="{{ old("page_settings.booking_page.{$key}", $bookingPage[$key] ?? $default) }}">
                        @endif
                    </label>
                @endforeach
                <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
                    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan Konten Booking Page</button>
                </div>
            </form>
        </div>
    </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="add-section" hidden>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body">
            <h3 style="margin-top:0;">Tambah Section</h3>
            <form method="POST" action="{{ route('admin.landing.sections.store') }}" class="grid grid-5">
                @csrf
                <input class="input" name="type" placeholder="section_type" required>
                <input class="input" name="title" placeholder="Judul section" required>
                <input class="input" name="subtitle" placeholder="Subtitle">
                <input class="input" name="icon" placeholder="lucide icon">
                <input class="input" type="number" name="sort_order" value="{{ ($page->sections->max('sort_order') ?? 0) + 10 }}" min="0" required>
                <button class="button button-primary" type="submit"><i data-lucide="plus"></i> Tambah</button>
            </form>
        </div>
    </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="sections" hidden>
    <div class="card" style="margin-bottom:1rem;">
        <div class="card-body" style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;">
            <div>
                <h3 style="margin:0;">Sections Editor</h3>
                <p class="muted" style="margin:.35rem 0 0;">Edit konten, item, status aktif, dan urutan section landing page.</p>
            </div>
            <span class="badge badge-gold">{{ $page->sections->count() }} sections</span>
        </div>
    </div>
    <div class="grid" style="margin-bottom:1rem;">
        @foreach($page->sections as $section)
            @php($content = $section->content ?: [])
            @php($items = $section->items ?: [])
            <div class="card landing-section-editor">
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.landing.sections.update', $section) }}">
                        @csrf @method('PUT')
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:1rem;">
                            <div><p class="eyebrow">{{ $section->type }}</p><h3 style="margin:.2rem 0;">{{ $section->title }}</h3><p class="muted">Urutan {{ $section->sort_order }} · {{ $section->is_active ? 'Aktif' : 'Nonaktif' }}</p></div>
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap;"><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
                        </div>
                        <div class="grid grid-5">
                            <input class="input" name="title" value="{{ $section->title }}" required>
                            <input class="input" name="subtitle" value="{{ $section->subtitle }}" placeholder="Subtitle">
                            <input class="input" name="icon" value="{{ $section->icon }}" placeholder="Icon">
                            <input class="input" type="number" name="sort_order" value="{{ $section->sort_order }}" min="0" required>
                            <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="is_active" value="1" @checked($section->is_active)> Aktif</label>
                        </div>

                        <h4>Content</h4>
                        <div class="grid grid-3">
                            @forelse($content as $key => $value)
                                <label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">{{ str_replace('_', ' ', $key) }}</span><textarea class="input" name="content[{{ $key }}]" rows="2">{{ is_array($value) ? json_encode($value) : $value }}</textarea></label>
                            @empty
                                <p class="muted">Belum ada content field.</p>
                            @endforelse
                        </div>

                        <h4>Items</h4>
                        <div data-items>
                            @forelse($items as $index => $item)
                                <div class="card" style="margin-bottom:.75rem;background:rgba(255,253,247,.72);"><div class="card-body grid grid-4" style="padding:1rem;">
                                    @foreach((array) $item as $key => $value)
                                        <label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">{{ str_replace('_', ' ', $key) }}</span><textarea class="input" name="items[{{ $index }}][{{ $key }}]" rows="2">{{ is_array($value) ? json_encode($value) : $value }}</textarea></label>
                                    @endforeach
                                    <button class="button button-ghost" type="button" data-remove-item>Hapus Item</button>
                                </div></div>
                            @empty
                                <p class="muted">Belum ada item.</p>
                            @endforelse
                        </div>
                        <button class="button button-secondary" type="button" data-add-item><i data-lucide="plus"></i> Tambah Item</button>
                    </form>
                    <form method="POST" action="{{ route('admin.landing.sections.destroy', $section) }}" style="margin-top:.75rem;" onsubmit="return confirm('Hapus section ini?')">@csrf @method('DELETE')<button class="button button-ghost" type="submit"><i data-lucide="trash"></i> Hapus Section</button></form>
                </div>
            </div>
        @endforeach
    </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="media" hidden>
    <div class="card">
        <div class="card-body">
            <h3 style="margin-top:0;">Media Library</h3>
            <form method="POST" action="{{ route('admin.landing.media.store') }}" class="grid grid-5" style="margin-bottom:1rem;">
                @csrf
                <input class="input" name="name" placeholder="Nama media" required>
                <input class="input" name="type" value="image" required>
                <input class="input" name="url" placeholder="URL/path media" required>
                <input class="input" name="alt_text" placeholder="Alt text">
                <label style="display:flex;align-items:center;gap:.5rem;"><input type="checkbox" name="is_active" value="1" checked> Aktif</label>
                <button class="button button-primary" type="submit"><i data-lucide="upload"></i> Tambah Media</button>
            </form>
            <div class="grid grid-3">
                @foreach($page->media as $media)
                    <div class="card"><div class="card-body"><img src="{{ $media->url }}" alt="{{ $media->alt_text }}" style="width:100%;height:140px;object-fit:cover;border-radius:1rem;"><h4>{{ $media->name }}</h4><p class="muted">{{ $media->url }}</p><form method="POST" action="{{ route('admin.landing.media.destroy', $media) }}">@csrf @method('DELETE')<button class="button button-ghost" type="submit">Hapus</button></form></div></div>
                @endforeach
            </div>
        </div>
    </div>
    </div>

    <div class="landing-tab-panel" data-landing-panel="preview" hidden>
        <div class="card">
            <div class="card-body">
                <h3 style="margin-top:0;">Preview & Publish</h3>
                <p class="muted">Cek tampilan draft landing page pada desktop, tablet, dan mobile sebelum publish.</p>
                <div class="grid grid-3" style="margin-top:1rem;">
                    <a class="card" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'desktop']) }}"><div class="card-body"><p class="eyebrow">Desktop</p><h3 style="margin:.2rem 0;">Preview Desktop</h3><p class="muted">Lebar penuh untuk layar besar.</p></div></a>
                    <a class="card" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'tablet']) }}"><div class="card-body"><p class="eyebrow">Tablet</p><h3 style="margin:.2rem 0;">Preview Tablet</h3><p class="muted">Simulasi layout medium.</p></div></a>
                    <a class="card" target="_blank" href="{{ route('admin.landing.preview', ['device'=>'mobile']) }}"><div class="card-body"><p class="eyebrow">Mobile</p><h3 style="margin:.2rem 0;">Preview Mobile</h3><p class="muted">Simulasi layar handphone.</p></div></a>
                </div>
                <form method="POST" action="{{ route('admin.landing.publish') }}" style="display:flex;justify-content:flex-end;margin-top:1rem;">
                    @csrf
                    <button class="button button-primary" type="submit"><i data-lucide="send"></i> Publish Landing Page</button>
                </form>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
<script>
document.querySelectorAll('[data-add-item]').forEach(function (button) {
    button.addEventListener('click', function () {
        const holder = button.closest('form').querySelector('[data-items]');
        const index = holder.querySelectorAll('.card').length;
        const wrap = document.createElement('div');
        wrap.className = 'card';
        wrap.style.cssText = 'margin-bottom:.75rem;background:rgba(255,253,247,.72);';
        wrap.innerHTML = '<div class="card-body grid grid-4" style="padding:1rem;"><label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">title</span><textarea class="input" name="items['+index+'][title]" rows="2"></textarea></label><label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">description</span><textarea class="input" name="items['+index+'][description]" rows="2"></textarea></label><label><span class="muted" style="display:block;margin-bottom:.35rem;font-weight:700;">icon/url/price</span><textarea class="input" name="items['+index+'][icon]" rows="2"></textarea></label><button class="button button-ghost" type="button" data-remove-item>Hapus Item</button></div>';
        holder.appendChild(wrap);
    });
});
document.addEventListener('click', function (event) {
    if (event.target.matches('[data-remove-item]')) event.target.closest('.card').remove();
});

const landingTabs = document.querySelectorAll('[data-landing-tab]');
const landingPanels = document.querySelectorAll('[data-landing-panel]');

function showLandingTab(tabName) {
    landingTabs.forEach(function (tab) {
        const active = tab.dataset.landingTab === tabName;
        tab.classList.toggle('button-primary', active);
        tab.classList.toggle('button-secondary', ! active);
    });

    landingPanels.forEach(function (panel) {
        panel.hidden = panel.dataset.landingPanel !== tabName;
    });
}

landingTabs.forEach(function (tab) {
    tab.addEventListener('click', function () {
        const tabName = tab.dataset.landingTab;
        showLandingTab(tabName);
        window.location.hash = 'tab-' + tabName;
    });
});

const initialTab = window.location.hash.replace('#tab-', '') || 'settings';
showLandingTab(document.querySelector('[data-landing-tab="' + initialTab + '"]') ? initialTab : 'settings');
</script>
@endpush
