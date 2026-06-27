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
</script>
@endpush
