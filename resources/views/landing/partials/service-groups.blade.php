<section class="section service-group-section" id="services" aria-labelledby="service-groups-title">
    <p class="eyebrow">PILIH LAYANAN GAYATRI</p>
    <h2 class="section-title" id="service-groups-title">Layanan sesuai kebutuhan Bunda.</h2>
    <p class="lead">Pijat dan spa serta pendampingan menyusui memiliki pilihan layanan dan halaman booking masing-masing.</p>
    <p><a class="btn btn-soft" href="{{ route('catalogue.index') }}">Lihat seluruh layanan, variasi & produk<i data-lucide="arrow-up-right"></i></a></p>
    <div class="service-group-cards">
        @foreach(\App\Support\ServiceGroup::LABELS as $groupKey => $groupLabel)
            @php($groupServices = $services->where('service_group', $groupKey))
            <article class="card service-group-card {{ $groupKey === 'lactation' ? 'service-group-lactation' : '' }}">
                <div class="icon"><i data-lucide="{{ $groupKey === 'lactation' ? 'heart-handshake' : 'sparkles' }}"></i></div>
                <p class="eyebrow">{{ $groupKey === 'lactation' ? 'PENDAMPINGAN MENYUSUI' : 'MOM & BABY CARE' }}</p>
                <h3>{{ $groupLabel }}</h3>
                <p class="lead">{{ $groupKey === 'lactation' ? 'Halaman khusus untuk memilih layanan laktasi dan konsultasi ASI beserta jadwalnya.' : 'Pilih layanan pijat, baby spa, serta perawatan Bunda dan si kecil.' }}</p>
                <ul class="service-group-list">
                    @forelse($groupServices->take(3) as $groupService)
                        <li><strong>{{ $groupService->name }}</strong><span>{{ $groupService->durationLabel() }} · {{ $groupService->priceLabel() }}</span></li>
                    @empty
                        <li>Informasi layanan dan jadwal dapat dikonfirmasi kepada admin.</li>
                    @endforelse
                </ul>
                <a class="btn btn-primary" href="{{ route('landing.booking-page', ['service_group' => $groupKey]) }}">{{ $groupKey === 'lactation' ? 'Booking Laktasi & ASI' : 'Booking Pijat & Spa' }}<i data-lucide="arrow-up-right"></i></a>
            </article>
        @endforeach
    </div>
</section>
