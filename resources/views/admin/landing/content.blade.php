@php
    $contact = $editor['contact'];
    $hero = $editor['hero'];
    $heroSection = $editor['sections']->get('hero');
    $savedContact = $editor['sections']->get('location_contact')?->content ?? [];
    $value = static function ($name, $default = '') {
        $input = old($name, $default);
        return is_scalar($input) ? (string) $input : '';
    };
    $faqItems = old('faq', app(\App\Services\Web\LandingContent::class)->faq($editor['sections']->get('faq'))->all());
    $faqItems = is_array($faqItems) ? array_values($faqItems) : [];
    $blankRows = min(2, max(0, 50 - count($faqItems)));
    for ($i = 0; $i < $blankRows; $i++) $faqItems[] = [];
@endphp
<form data-guard-unsaved method="POST" action="{{ route('admin.landing.content.update') }}">
    @csrf
    @method('PUT')
    <div class="card" style="margin-bottom:1rem">
        <div class="card-body">
            <h3 style="margin-top:0">Pembuka beranda</h3>
            <p class="muted">Atur teks dan latar pembuka. Video suasana spa menyesuaikan layar: mendatar pada desktop dan tegak 9:16 pada ponsel. Pilih mode gambar untuk memakai foto dari pustaka media.</p>
            <div class="grid grid-2">
                <label>Judul utama <input class="input" name="headline" value="{{ $value('headline', $hero['headline']) }}" maxlength="150" required></label>
                <label>Latar pembuka
                    <select class="input" name="hero_background">
                        <option value="video" @selected($value('hero_background', $hero['video'] ? 'video' : 'image') === 'video')>Video suasana spa</option>
                        <option value="image" @selected($value('hero_background', $hero['video'] ? 'video' : 'image') === 'image')>Gambar pilihan</option>
                    </select>
                </label>
                <label>Foto untuk mode gambar
                    <select class="input" name="hero_media_id">
                        <option value="">Gunakan gambar lama atau bawaan</option>
                        @foreach($editor['images'] as $image)
                            <option value="{{ $image->id }}" @selected($value('hero_media_id', $heroSection?->content['media_id'] ?? '') === (string) $image->id)>{{ $image->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="grid-column:1/-1">Pengantar <textarea class="input" name="intro" maxlength="500" rows="3" required>{{ $value('intro', $hero['description']) }}</textarea></label>
            </div>
        </div>
    </div>
    <div class="card" style="margin-bottom:1rem">
        <div class="card-body">
            <h3 style="margin-top:0">Identitas dan kontak usaha</h3>
            <p class="muted">Gunakan informasi yang sama dengan yang Bunda lihat di outlet dan profil bisnis. Nomor ini dipakai tombol tanya admin pada katalog.</p>
            <div class="grid grid-2">
                <label>Nama usaha <input class="input" name="business_name" value="{{ $value('business_name', $contact['name']) }}" maxlength="150" required></label>
                <label>Kota <input class="input" name="city" value="{{ $value('city', $contact['city']) }}" maxlength="100" required></label>
                <label>Alamat usaha <input class="input" name="address" value="{{ $value('address', $contact['address']) }}" maxlength="250" required></label>
                <label>WhatsApp publik <input class="input" type="tel" name="whatsapp" value="{{ $value('whatsapp', $contact['phone']) }}" maxlength="30" required></label>
                <label>Jam kunjungan <input class="input" name="hours" value="{{ $value('hours', $contact['hours']) }}" maxlength="180"></label>
                <label>Tautan Maps <input class="input" type="url" name="maps_url" value="{{ $value('maps_url', \App\Services\Web\LandingPresentation::url($savedContact['maps_url'] ?? null)) }}" maxlength="1000" placeholder="Otomatis dicari dari alamat jika dikosongkan"></label>
            </div>
        </div>
    </div>
    <div class="card" style="margin-bottom:1rem">
        <div class="card-body">
            <h3 style="margin-top:0">Pencarian dan berbagi tautan</h3>
            <p class="muted">Kosongkan untuk memakai judul, deskripsi, atau gambar bawaan. Periksa hasilnya di tab Pratinjau &amp; terbitkan.</p>
            <div class="grid grid-2">
                <label>Judul pencarian <input class="input" name="meta_title" value="{{ $value('meta_title', $editor['seo']['customTitle']) }}" placeholder="{{ $editor['seo']['title'] }}" maxlength="70"></label>
                <label>Gambar saat tautan dibagikan
                    <select class="input" name="share_media_id">
                        <option value="">Gunakan gambar berbagi bawaan</option>
                        @foreach($editor['images'] as $image)
                            <option value="{{ $image->id }}" @selected($value('share_media_id', $page->seo_settings['share_media_id'] ?? '') === (string) $image->id)>{{ $image->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label style="grid-column:1/-1">Deskripsi pencarian <textarea class="input" name="meta_description" placeholder="{{ $editor['seo']['description'] }}" maxlength="170" rows="3">{{ $value('meta_description', $editor['seo']['customDescription']) }}</textarea></label>
            </div>
        </div>
    </div>
    <details class="ops-section" style="margin-bottom:1rem" @if($errors->has('faq.*')) open @endif>
        <summary>Pertanyaan yang sering diajukan (FAQ)</summary>
        <div class="ops-section-body">
            <p class="muted">Isi pertanyaan dan jawaban sebagai satu pasangan. Kosongkan keduanya untuk menghapus baris. Dua baris kosong baru tersedia setelah menyimpan.</p>
            @foreach($faqItems as $item)
                <fieldset class="ops-fields" style="margin-bottom:.75rem;min-width:0">
                    <legend>FAQ {{ $loop->iteration }}</legend>
                    <label>Pertanyaan <input class="input" name="faq[{{ $loop->index }}][title]" maxlength="200" value="{{ is_string($item['title'] ?? null) ? $item['title'] : '' }}"></label>
                    <label>Jawaban <textarea class="input" name="faq[{{ $loop->index }}][description]" maxlength="1200" rows="2">{{ is_string($item['description'] ?? null) ? $item['description'] : '' }}</textarea></label>
                </fieldset>
            @endforeach
        </div>
    </details>
    <button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan konten beranda</button>
</form>
