<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $bookingContent['meta_title'] }}</title>
    <meta name="description" content="{{ $bookingContent['meta_description'] }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{--brown:#7a4e2d;--dark:#3d2415;--gold:#c9a227;--soft-gold:#e8d8a8;--cream:#f8f3ea;--ivory:#fffdf7;--taupe:#d8c3a5;--muted:#7b6a5a;--green:#3f6d3d;--red:#7a3228;--shadow:0 24px 70px rgba(74,44,26,.14)}*{box-sizing:border-box}body{margin:0;color:var(--dark);font-family:Inter,system-ui,sans-serif;background:radial-gradient(circle at 10% 0,rgba(201,162,39,.30),transparent 24rem),radial-gradient(circle at 90% 20%,rgba(122,78,45,.16),transparent 28rem),linear-gradient(135deg,var(--cream),var(--ivory) 58%,#efe2d0)}a{text-decoration:none;color:inherit}.nav{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1rem clamp(1rem,4vw,4rem);position:sticky;top:0;z-index:10;background:rgba(255,253,247,.78);backdrop-filter:blur(18px);border-bottom:1px solid rgba(216,195,165,.62)}.brand{display:flex;align-items:center;gap:.75rem;font-weight:900;color:var(--brown)}.mark{display:grid;place-items:center;width:44px;height:44px;border-radius:17px;background:linear-gradient(135deg,var(--soft-gold),var(--gold));box-shadow:0 14px 28px rgba(201,162,39,.25)}.btn{display:inline-flex;align-items:center;justify-content:center;gap:.55rem;border:0;border-radius:999px;padding:.9rem 1.2rem;font-weight:900;cursor:pointer}.btn-primary{color:#fff;background:linear-gradient(135deg,var(--brown),#4a2c1a);box-shadow:0 16px 32px rgba(74,44,26,.22)}.btn-soft{color:var(--brown);background:rgba(232,216,168,.58)}.wrap{width:min(1180px,100%);margin:0 auto;padding:clamp(1rem,4vw,3rem)}.hero{display:grid;grid-template-columns:.95fr 1.05fr;gap:2rem;align-items:center;padding:2rem 0 1rem}.eyebrow{margin:0 0 .7rem;color:var(--gold);font-size:.78rem;font-weight:900;letter-spacing:.14em;text-transform:uppercase}.headline{margin:0;font-family:'Playfair Display',serif;font-size:clamp(2.4rem,6vw,5rem);line-height:.96;color:#4a2c1a}.lead{color:var(--muted);line-height:1.8;font-size:1.04rem}.hero-visual{position:relative;min-height:420px;border-radius:34px;overflow:hidden;box-shadow:var(--shadow);background:linear-gradient(135deg,#efe2d0,#d8c3a5)}.hero-visual:before{content:'';position:absolute;inset:0;background:var(--booking-hero-image) center/cover}.hero-visual:after{content:'';position:absolute;inset:0;background:linear-gradient(180deg,transparent,rgba(74,44,26,.72))}.floating{position:absolute;left:1rem;right:1rem;bottom:1rem;z-index:2;display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem}.mini{padding:1rem;border:1px solid rgba(255,255,255,.42);border-radius:20px;background:rgba(255,253,247,.86);backdrop-filter:blur(16px);font-weight:900}.grid{display:grid;gap:1rem}.grid-2{grid-template-columns:1fr 1fr}.grid-3{grid-template-columns:repeat(3,1fr)}.card{border:1px solid rgba(216,195,165,.78);border-radius:28px;background:rgba(255,253,247,.88);box-shadow:0 16px 42px rgba(74,44,26,.09);padding:1.3rem}.booking-panel{display:grid;grid-template-columns:.78fr 1.22fr;gap:1.2rem;align-items:start;margin-top:1rem}.status-card{background:linear-gradient(135deg,rgba(122,78,45,.96),rgba(74,44,26,.98));color:#fff}.status-card .lead{color:rgba(255,255,255,.75)}.step{display:flex;gap:.8rem;align-items:flex-start;margin-top:1rem}.step-no{display:grid;place-items:center;flex:0 0 36px;height:36px;border-radius:14px;background:linear-gradient(135deg,var(--soft-gold),var(--gold));color:#3d2415;font-weight:900}.alert{margin-bottom:1rem;border-radius:20px;padding:1rem 1.1rem;font-weight:800}.alert-success{border:1px solid rgba(143,174,139,.7);background:rgba(143,174,139,.16);color:var(--green)}.alert-error{border:1px solid rgba(201,122,106,.7);background:rgba(201,122,106,.14);color:var(--red)}.input{width:100%;min-height:52px;border:1px solid var(--taupe);border-radius:17px;padding:.9rem 1rem;background:#fffdf7;color:var(--dark);outline:none}.input:focus{border-color:var(--gold);box-shadow:0 0 0 4px rgba(201,162,39,.14)}textarea.input{min-height:118px;resize:vertical}.label{display:block;margin:0 0 .4rem;color:var(--muted);font-weight:900;font-size:.86rem}.summary{display:grid;gap:.7rem}.summary-row{display:flex;justify-content:space-between;gap:1rem;padding:.85rem 0;border-bottom:1px dashed rgba(216,195,165,.9)}.badge{display:inline-flex;width:max-content;align-items:center;border-radius:999px;padding:.45rem .7rem;font-size:.78rem;font-weight:900;background:rgba(232,216,168,.62);color:var(--brown)}.footer-note{text-align:center;color:var(--muted);padding:2rem 1rem 3rem}@media(max-width:920px){.hero,.booking-panel,.grid-2,.grid-3{grid-template-columns:1fr}.hero-visual{min-height:300px}.floating{grid-template-columns:1fr}.nav{align-items:flex-start}.headline{font-size:3rem}.wrap{padding:1rem}.card{border-radius:22px;padding:1rem}.booking-panel{margin-top:.4rem}}@media(max-width:520px){.nav{flex-direction:column}.nav .btn{width:100%}.hero{padding-top:1rem}.headline{font-size:2.55rem}.btn{width:100%}.summary-row{display:block}.mini{padding:.85rem}}
    </style>
</head>
<body>
    <nav class="nav">
        <a class="brand" href="{{ route('landing.show') }}"><span class="mark"><i data-lucide="flower-2"></i></span>Gayatri Mom & Baby SPA</a>
        <a class="btn btn-soft" href="{{ route('landing.show') }}"><i data-lucide="arrow-left"></i> {{ $bookingContent['nav_back_label'] }}</a>
    </nav>

    <main class="wrap">
        <section class="hero">
            <div>
                <p class="eyebrow">{{ $bookingContent['eyebrow'] }}</p>
                <h1 class="headline">{{ $bookingContent['headline'] }}</h1>
                <p class="lead">{{ $bookingContent['lead'] }}</p>
                <div class="grid grid-3" style="margin-top:1.2rem;">
                    <div class="card"><strong>{{ $bookingContent['hero_badge_1_title'] }}</strong><p class="lead" style="margin:.4rem 0 0;">{{ $bookingContent['hero_badge_1_text'] }}</p></div>
                    <div class="card"><strong>{{ $bookingContent['hero_badge_2_title'] }}</strong><p class="lead" style="margin:.4rem 0 0;">{{ $bookingContent['hero_badge_2_text'] }}</p></div>
                    <div class="card"><strong>{{ $bookingContent['hero_badge_3_title'] }}</strong><p class="lead" style="margin:.4rem 0 0;">{{ $bookingContent['hero_badge_3_text'] }}</p></div>
                </div>
            </div>
            <div class="hero-visual" style="--booking-hero-image:url('{{ $bookingContent['hero_image_url'] }}');">
                <div class="floating"><div class="mini">{{ $bookingContent['floating_1'] }}</div><div class="mini">{{ $bookingContent['floating_2'] }}</div><div class="mini">{{ $bookingContent['floating_3'] }}</div></div>
            </div>
        </section>

        <section class="booking-panel">
            <aside class="card status-card">
                <span class="badge">{{ $bookingContent['status_badge'] }}</span>
                @if($booking)
                    <h2 style="margin:.8rem 0 .4rem;">{{ $booking->status === 'pending_confirmation' ? $bookingContent['pending_status_title'] : $bookingContent['processed_status_title'] }}</h2>
                    <p class="lead">Kode booking: <strong>{{ $booking->booking_code }}</strong></p>
                    <div class="summary">
                        <div class="summary-row"><span>Layanan</span><strong>{{ $booking->service?->name ?: '-' }}</strong></div>
                        <div class="summary-row"><span>Cabang</span><strong>{{ $booking->branch?->name ?: 'Mengikuti layanan' }}</strong></div>
                        <div class="summary-row"><span>Tanggal</span><strong>{{ $booking->booking_date?->format('d M Y') }}</strong></div>
                        <div class="summary-row"><span>Jam</span><strong>{{ substr((string) $booking->start_time, 0, 5) }}</strong></div>
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
                @if(session('booking_status'))<div class="alert alert-success"><i data-lucide="check-circle"></i> {{ session('booking_status') }}</div>@endif
                @if($errors->any())<div class="alert alert-error"><i data-lucide="alert-circle"></i> {{ $errors->first() }}</div>@endif

                @if($booking && $booking->status === 'pending_confirmation')
                    <p class="eyebrow">{{ $bookingContent['reschedule_eyebrow'] }}</p>
                    <h2 style="margin:.2rem 0 1rem;font-family:'Playfair Display',serif;font-size:2rem;">{{ $bookingContent['reschedule_title'] }}</h2>
                    <form method="POST" action="{{ route('landing.booking.reschedule') }}" class="grid grid-2">
                        @csrf
                        @include('landing.partials.booking-fields', ['booking' => $booking])
                        <button class="btn btn-primary" type="submit" style="grid-column:1/-1;"><i data-lucide="refresh-cw"></i> {{ $bookingContent['reschedule_button'] }}</button>
                    </form>
                @else
                    <p class="eyebrow">{{ $bookingContent['form_eyebrow'] }}</p>
                    <h2 style="margin:.2rem 0 1rem;font-family:'Playfair Display',serif;font-size:2rem;">{{ $bookingContent['form_title'] }}</h2>
                    <form method="POST" action="{{ route('landing.booking') }}" class="grid grid-2">
                        @csrf
                        <label><span class="label">Nama Bunda</span><input class="input" name="name" value="{{ old('name') }}" placeholder="Contoh: Bunda Alya" required></label>
                        <label><span class="label">Step 1 - Nomor WhatsApp Aktif</span><input class="input" name="whatsapp_number" value="{{ old('whatsapp_number') }}" placeholder="08xxxxxxxxxx" inputmode="tel" autocomplete="tel" required><span class="lead" style="display:block;margin:.35rem 0 0;font-size:.86rem;">Wajib diisi manual agar nomor tersimpan benar di dashboard CRM.</span></label>
                        <label><span class="label">Email</span><input class="input" type="email" name="email" value="{{ old('email') }}" placeholder="opsional"></label>
                        <label><span class="label">Nama Bayi/Anak</span><input class="input" name="baby_name" value="{{ old('baby_name') }}" placeholder="opsional"></label>
                        @include('landing.partials.booking-fields', ['booking' => null])
                        <button class="btn btn-primary" type="submit" style="grid-column:1/-1;"><i data-lucide="send"></i> {{ $bookingContent['submit_button'] }}</button>
                    </form>
                @endif
            </div>
        </section>
    </main>

    <p class="footer-note">{{ $bookingContent['footer_note'] }}</p>
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js"></script><script>if(window.lucide){window.lucide.createIcons()}</script>
</body>
</html>
