<div class="card" style="margin-bottom:1rem">
    <div class="card-body">
        <div class="grid grid-2">
            <section aria-label="Pemeriksaan sebelum terbit">
                <h3 style="margin-top:0">Pemeriksaan sebelum terbit</h3>
                <ul>
                    @foreach($editor['checks'] as $label => $ready)
                        <li><strong>{{ $ready ? 'Siap' : 'Perlu diisi' }}</strong> — {{ $label }}</li>
                    @endforeach
                </ul>
                <p class="muted">Periksa apakah alamat, jam, dan nomor WhatsApp masih berlaku. Buka pratinjau ponsel dan periksa tombol layanan serta gambar dari tautan luar sebelum menerbitkan.</p>
                <p class="muted"><a href="{{ route('public.sitemap') }}" target="_blank" rel="noopener">Lihat sitemap XML</a> · <a href="{{ route('public.robots') }}" target="_blank" rel="noopener">Lihat robots.txt</a></p>
            </section>
            <section aria-label="Pratinjau tautan">
                <h3 style="margin-top:0">Pratinjau saat tautan dibagikan</h3>
                <img src="{{ $editor['seo']['image'] }}" alt="{{ $editor['seo']['imageAlt'] }}" width="320" height="170" loading="lazy" style="display:block;max-width:100%;height:auto;aspect-ratio:1.9;object-fit:cover;border-radius:.65rem">
                <p style="margin:.6rem 0 .15rem"><strong>{{ $editor['seo']['title'] }}</strong></p>
                <p class="muted" style="margin:0">{{ $editor['seo']['description'] }}</p>
                <small class="muted">{{ $editor['seo']['canonical'] }}</small>
            </section>
        </div>
    </div>
</div>
