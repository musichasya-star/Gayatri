<x-public.page :title="$bookingContent['meta_title']" :description="$bookingContent['meta_description']" page-class="gp-booking">
<x-slot:head>
    <link rel="stylesheet" href="{{ asset('css/gayatri-booking.css').'?v='.config('gayatri_frontend.asset_version') }}">
    <link rel="stylesheet" href="{{ asset('css/gayatri-transactions.css').'?v='.config('gayatri_frontend.asset_version') }}">
</x-slot:head>
<x-slot:scripts><script src="{{ asset('js/public-transactions.js') }}" defer></script></x-slot:scripts>
<nav class="gp-breadcrumb" aria-label="Jejak halaman"><a href="{{ route('landing.show') }}">Beranda</a><span aria-hidden="true">/</span><a data-return-catalogue href="{{ \App\Services\Web\PublicCatalogue::backUrl(old('return_to',request('return_to')),route('catalogue.index',['group'=>$serviceGroup])) }}">Kembali ke katalog</a><span aria-hidden="true">/</span><span>{{ \App\Support\ServiceGroup::LABELS[$serviceGroup] }}</span></nav>
<nav class="gp-tabs" aria-label="Pilih kelompok layanan">@foreach(\App\Support\ServiceGroup::LABELS as $key=>$label)<a href="{{ route('landing.booking-page',['service_group'=>$key]) }}" @if($serviceGroup===$key) aria-current="page" @endif>{{ $label }}</a>@endforeach</nav>
<x-public.service-hero :role="$serviceGroup==='lactation'?'lactation':'spa'" :eyebrow="$bookingContent['eyebrow']" :title="$bookingContent['headline']" :lead="$bookingContent['lead']"><a class="gp-button gp-button-primary" href="#booking-form">{{ $booking ? 'Lihat pemesanan' : 'Pilih layanan & jadwal' }}<x-public.icon name="arrow"/></a><a class="gp-button gp-button-secondary" href="{{ route('catalogue.index',['group'=>$serviceGroup]) }}">Lihat seluruh pilihan</a></x-public.service-hero>
<p class="gp-info">Pemesanan mengikuti jadwal yang tersedia. Paket beberapa sesi dan pilihan yang memerlukan konfirmasi dapat dilihat melalui katalog. Pembayaran dilakukan manual sesuai instruksi pesanan atau admin.</p>
@if($availabilitySlots->isEmpty())<p class="gp-info" role="status">Jadwal online untuk layanan ini belum tersedia. Bunda bisa melihat pilihan layanan dan menghubungi admin melalui katalog untuk konfirmasi.</p>@endif
        <section class="booking-panel" id="booking-form">
            <aside class="card status-card">
                <span class="badge">{{ $bookingContent['status_badge'] }}</span>
                @if($booking)
                    <h2 style="margin:.8rem 0 .4rem;">{{ $booking->status === 'pending_confirmation' ? $bookingContent['pending_status_title'] : $bookingContent['processed_status_title'] }}</h2>
                    <p class="lead">Kode booking: <strong>{{ $booking->booking_code }}</strong></p>
                    <div class="summary">
                        <div class="summary-row"><span>Layanan</span><strong>{{ $booking->service?->name ?: '-' }}</strong></div>
                        @if($booking->service_mode === 'home')
                            <div class="summary-row"><span>Home Service</span><strong>{{ $booking->visit_address }}</strong></div>
                            <div class="summary-row"><span>Ongkos perjalanan</span><strong>Rp {{ number_format((float) $booking->travel_fee, 0, ',', '.') }}</strong></div>
                        @endif
                        <div class="summary-row"><span>Cabang</span><strong>{{ $booking->branch?->name ?: 'Mengikuti layanan' }}</strong></div>
                        <div class="summary-row"><span>Tanggal</span><strong>{{ $booking->booking_date?->format('d M Y') }}</strong></div>
                        <div class="summary-row"><span>Jam</span><strong>{{ substr((string) $booking->start_time, 0, 5) }}</strong></div>
                        <div class="summary-row"><span>Total booking</span><strong>Rp {{ number_format($booking->totalPrice(), 0, ',', '.') }}</strong></div>
                        <div class="summary-row"><span>Pembayaran</span><strong>{{ match($booking->payment_status) {'paid'=>'Lunas', 'partial'=>'Dibayar sebagian', 'refunded'=>'Dana dikembalikan', default=>'Belum dibayar'} }}</strong></div>
                    </div>
                    @if($booking->status === 'pending_confirmation')
                        <p class="lead">{{ $bookingContent['pending_note'] }}</p>
                    @else
                        <p class="lead">{{ $bookingContent['processed_note'] }}</p>
                    @endif
                @else
                    <h2 style="margin:.8rem 0 .4rem;">{{ $bookingContent['empty_status_title'] }}</h2>
                    <p class="lead">{{ $bookingContent['empty_status_text'] }}</p>
                @endif
                <div class="step"><span class="step-no">1</span><div><strong>{{ $bookingContent['step_1_title'] }}</strong><p class="lead" style="margin:.2rem 0 0;">{{ $bookingContent['step_1_text'] }}</p></div></div>
                <div class="step"><span class="step-no">2</span><div><strong>{{ $bookingContent['step_2_title'] }}</strong><p class="lead" style="margin:.2rem 0 0;">{{ $bookingContent['step_2_text'] }}</p></div></div>
                <div class="step"><span class="step-no">3</span><div><strong>{{ $bookingContent['step_3_title'] }}</strong><p class="lead" style="margin:.2rem 0 0;">{{ $bookingContent['step_3_text'] }}</p></div></div>
                <div class="step"><span class="step-no">4</span><div><strong>{{ $bookingContent['step_4_title'] }}</strong><p class="lead" style="margin:.2rem 0 0;">{{ $bookingContent['step_4_text'] }}</p></div></div>
            </aside>

            <div class="card">
                @if(session('booking_status'))<div class="alert alert-success"><x-public.icon name="check"/> {{ session('booking_status') }}</div>@endif
                @if($errors->any())
                    <div class="alert alert-error" role="alert">
                        <strong>Periksa kembali sebelum mengirim</strong>
                        <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                    </div>
                @endif

                @if($booking && $booking->status === 'pending_confirmation')
                    <p class="eyebrow">{{ $bookingContent['reschedule_eyebrow'] }}</p>
                    <h2 style="margin:.2rem 0 1rem;font-family:'Playfair Display',serif;font-size:2rem;">{{ $bookingContent['reschedule_title'] }}</h2>
                    <form method="POST" action="{{ route('landing.booking.reschedule') }}" class="grid" data-transaction-submit>
                        @csrf
                        @include('landing.partials.booking-fields', ['booking' => $booking])
                        <button class="btn btn-primary" type="submit" style="grid-column:1/-1;"> {{ $bookingContent['reschedule_button'] }}</button>
                        <span class="transaction-progress" data-transaction-progress role="status"></span>
                    </form>
                @else
                    <p class="eyebrow">{{ $bookingContent['form_eyebrow'] }}</p>
                    <h2 style="margin:.2rem 0 1rem;font-family:'Playfair Display',serif;font-size:2rem;">{{ $bookingContent['form_title'] }}</h2>
                    <form method="POST" action="{{ route('landing.booking') }}" class="grid" data-transaction-submit>
                        @csrf
                        @include('landing.partials.booking-fields', ['booking' => null])
                        <button class="btn btn-primary" type="submit" @disabled($availabilitySlots->isEmpty() || $services->isEmpty())><x-public.icon name="arrow"/> {{ $bookingContent['submit_button'] }}</button>
                        <span class="transaction-progress" data-transaction-progress role="status"></span>
                    </form>
                @endif
            </div>
        </section>
<p class="gp-booking-privacy">{{ $bookingContent['footer_note'] }}</p>
</x-public.page>
