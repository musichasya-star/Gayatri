@php
    $fieldId = $booking ? 'reschedule-booking' : 'new-booking';
    $selectedServiceId = (string) old('service_id', $booking?->service_id ?? ($services->contains('id', request()->integer('service_id')) ? request()->integer('service_id') : null));
    $selectedBranchId = (string) old('branch_id', $booking?->branch_id);
    $selectedSlotId = (string) old('availability_slot_id', $booking?->availability_slot_id);
    $selectedAddonIds = collect(old('addons', $booking?->addOns?->pluck('service_addon_id')->filter()->values()->all() ?? []))->map(fn ($id) => (string) $id)->all();
    $selectedDate = old('booking_date', $booking?->booking_date?->format('Y-m-d'));
    $selectedTime = old('start_time', $booking ? substr((string) $booking->start_time, 0, 5) : null);
    $slotOptions = $availabilitySlots->map(fn ($slot) => [
        'id' => $slot->id,
        'service_id' => $slot->service_id,
        'branch_id' => $slot->branch_id,
        'date' => $slot->slot_date?->toDateString(),
        'date_label' => $slot->slot_date?->translatedFormat('d F Y'),
        'start_time' => substr((string) $slot->start_time, 0, 5),
        'end_time' => substr((string) $slot->end_time, 0, 5),
        'service_name' => $slot->service?->name,
        'branch_name' => $slot->branch?->name ?: 'Semua cabang',
        'therapist_name' => $slot->therapist?->name ?: 'Terapis siapa saja',
        'therapist_id' => $slot->therapist_id,
        'remaining' => max(0, (int) $slot->capacity - (int) $slot->booked_count),
    ])->values();
    $fieldIds = [
        'service' => $fieldId.'-service',
        'branch' => $fieldId.'-branch',
        'date' => $fieldId.'-date',
        'slot' => $fieldId.'-slot',
        'dateValue' => $fieldId.'-date-value',
        'timeValue' => $fieldId.'-time-value',
        'help' => $fieldId.'-slot-help',
        'addons' => $fieldId.'-addons',
        'addonHelp' => $fieldId.'-addon-help',
    ];
    $selectedValues = [
        'date' => $selectedDate,
        'slotId' => $selectedSlotId,
        'addonIds' => $selectedAddonIds,
        'savedClinicServiceId' => $booking?->service_mode === 'clinic' ? $booking->service_id : null,
        'savedClinicPrice' => $booking?->service_mode === 'clinic' ? $booking->service_price_snapshot : null,
    ];
    $addonOptions = $services->mapWithKeys(fn ($service) => [
        $service->id => $service->activeAddOns->map(fn ($addon) => [
            'id' => $addon->id,
            'price' => (float) $addon->price_adjustment,
            'minutes' => (int) $addon->duration_minutes,
            'label' => ($addon->displayName() ?: 'Addon layanan').' +'.$addon->duration_minutes.' menit + Rp '.number_format((float) $addon->price_adjustment, 0, ',', '.'),
        ])->values(),
    ]);
@endphp

<input type="hidden" name="service_group" value="{{ $serviceGroup }}">
<fieldset class="transaction-fields">
<legend>1. Pilih layanan dan variasi</legend>
@if($services->isEmpty())<p class="lead" role="status" style="grid-column:1/-1;">Belum ada layanan aktif untuk kelompok ini. Silakan hubungi admin untuk informasi layanan dan jadwal.</p>@endif
<label style="grid-column:1/-1;">Kelompok produk / jenis perawatan<select class="input" data-service-family-filter><option value="">Semua pilihan layanan</option>@foreach($services->groupBy(fn($s)=>$s->catalog_family ?: $s->name) as $family=>$variants)<option value="{{ $family }}" @selected($variants->contains('id',(int)$selectedServiceId))>{{ $family }}</option>@endforeach</select><small class="lead" style="display:block;font-size:.85rem;">Untuk paket beberapa sesi/hari atau pilihan yang perlu konfirmasi, <a href="{{ route('catalogue.index',['group'=>$serviceGroup]) }}">lihat katalog lengkap</a>.</small></label>
<label>
    <span class="label">Layanan {{ \App\Support\ServiceGroup::LABELS[$serviceGroup] }}</span>
    <select class="input" name="service_id" id="{{ $fieldId }}-service" required>
        <option value="">Pilih layanan</option>
        @foreach($services as $service)
            <option data-family="{{ $service->catalog_family ?: $service->name }}" data-name="{{ $service->name }}" data-price="{{ $service->price }}" data-minutes="{{ $service->duration_minutes }}" value="{{ $service->id }}" @selected($selectedServiceId === (string) $service->id)>{{ $service->name }} - Rp {{ number_format((float) $service->price, 0, ',', '.') }}</option>
        @endforeach
    </select>
</label>
<div style="grid-column:1/-1;">
    <span class="label">Addon / Tambah Layanan</span>
    <div id="{{ $fieldId }}-addons" style="display:grid;gap:.55rem;"></div>
    <span class="lead" id="{{ $fieldId }}-addon-help" style="display:block;margin:.35rem 0 0;font-size:.86rem;">Pilih layanan untuk melihat addon yang tersedia.</span>
</div>
</fieldset>
<fieldset class="transaction-fields">
<legend>2. Tentukan lokasi dan jadwal</legend>
@include('partials.home-service-fields', ['booking' => $booking, 'homeSettings' => $homeSettings])
<label>
    <span class="label">Cabang</span>
    <select class="input" name="branch_id" id="{{ $fieldId }}-branch">
        <option value="">Pilih cabang / semua cabang</option>
        @foreach($branches as $branch)
            <option value="{{ $branch->id }}" @selected($selectedBranchId === (string) $branch->id)>{{ $branch->name }}</option>
        @endforeach
    </select>
</label>
<input type="hidden" name="booking_date" id="{{ $fieldId }}-date-value" value="{{ $selectedDate }}">
<input type="hidden" name="start_time" id="{{ $fieldId }}-time-value" value="{{ $selectedTime }}">
<label>
    <span class="label">Tanggal kunjungan</span>
    <select class="input" id="{{ $fieldId }}-date" required>
        <option value="">Pilih layanan dulu</option>
    </select>
</label>
<label>
    <span class="label">Jam Mulai</span>
    <select class="input" name="availability_slot_id" id="{{ $fieldId }}-slot" required>
        <option value="">Pilih tanggal dulu</option>
    </select>
    <span class="lead" id="{{ $fieldId }}-slot-help" style="display:block;margin:.35rem 0 0;font-size:.86rem;">Jam mengikuti slot yang sudah diinput admin dan masih tersedia.</span>
</label>
</fieldset>

@unless($booking)
    <fieldset class="transaction-fields">
        <legend>3. Isi kontak pemesan</legend>
        <label><span class="label">Nama Bunda</span><input class="input" name="name" maxlength="150" value="{{ old('name') }}" autocomplete="name" required></label>
        <label><span class="label">Nomor WhatsApp aktif</span><input class="input" name="whatsapp_number" maxlength="30" value="{{ old('whatsapp_number') }}" placeholder="08xxxxxxxxxx" inputmode="tel" autocomplete="tel" required><small>Admin menggunakan nomor ini untuk konfirmasi.</small></label>
        <label><span class="label">Email (opsional)</span><input class="input" type="email" name="email" maxlength="150" value="{{ old('email') }}" autocomplete="email"></label>
        <label><span class="label">Nama bayi atau anak (opsional)</span><input class="input" name="baby_name" maxlength="150" value="{{ old('baby_name') }}"></label>
    </fieldset>
@endunless
<label style="grid-column:1/-1;">
    <span class="label">Catatan untuk Admin</span>
    <textarea class="input" name="notes" maxlength="2000" placeholder="Contoh: kebutuhan khusus untuk kunjungan.">{{ old('notes', $booking?->notes) }}</textarea>
</label>
<section class="transaction-wide" aria-labelledby="{{ $fieldId }}-recap-title" data-booking-recap>
    <h3 id="{{ $fieldId }}-recap-title">Ringkasan sebelum dikirim</h3>
    <dl class="transaction-recap" aria-live="polite">
        <div><dt>Layanan dan durasi</dt><dd data-recap-service>Pilih layanan</dd></div>
        <div><dt>Jadwal dan lokasi</dt><dd data-recap-slot>Pilih jadwal</dd></div>
        <div><dt>Estimasi sebelum promo</dt><dd data-recap-total>Menunggu pilihan</dd></div>
    </dl>
    <p class="lead">Admin akan mengonfirmasi permintaan sebelum pembayaran.</p>
</section>

<script>
(() => {
    const slots = @json($slotOptions);
    const ids = @json($fieldIds);
    const selected = @json($selectedValues);
    const service = document.getElementById(ids.service);
    const branch = document.getElementById(ids.branch);
    const date = document.getElementById(ids.date);
    const slot = document.getElementById(ids.slot);
    const dateValue = document.getElementById(ids.dateValue);
    const timeValue = document.getElementById(ids.timeValue);
    const help = document.getElementById(ids.help);
    const addonTarget = document.getElementById(ids.addons);
    const addonHelp = document.getElementById(ids.addonHelp);
    const addonOptions = @json($addonOptions);
    const selectedAddons = new Set(selected.addonIds || []);
    const form = service.closest('form');
    const mode = form.querySelector('[name="service_mode"]');
    const recap = form.querySelector('[data-booking-recap]');
    const familyFilter = form.querySelector('[data-service-family-filter]');
    const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    let homeTotal = null;

    const updateRecap = () => {
        const option = service.selectedOptions[0];
        const extras = (addonOptions[service.value] || []).filter(item => selectedAddons.has(String(item.id)));
        const minutes = Number(option?.dataset.minutes || 0) + extras.reduce((sum, item) => sum + item.minutes, 0);
        const useSavedPrice = selected.savedClinicPrice !== null && String(selected.savedClinicServiceId) === service.value;
        const price = useSavedPrice ? selected.savedClinicPrice : option?.dataset.price;
        const total = Number(price || 0) + extras.reduce((sum, item) => sum + item.price, 0);
        const appointment = slots.find(item => String(item.id) === slot.value);
        recap.querySelector('[data-recap-service]').textContent = service.value ? `${option.dataset.name} · ${minutes} menit` : 'Pilih layanan';
        recap.querySelector('[data-recap-slot]').textContent = appointment
            ? `${appointment.date_label}, ${appointment.start_time} WIB · ${mode?.value === 'home' ? 'Kunjungan rumah' : appointment.branch_name}`
            : 'Pilih jadwal yang tersedia';
        recap.querySelector('[data-recap-total]').textContent = !service.value ? 'Menunggu pilihan'
            : mode?.value === 'home' ? (homeTotal === null ? 'Hitung ongkos perjalanan' : money.format(homeTotal)) : money.format(total);
    };
    const filterFamilies = () => {
        let valid = true;
        [...service.options].forEach(option => {
            if (!option.value) return;
            option.hidden = !!familyFilter.value && option.dataset.family !== familyFilter.value;
            option.disabled = option.hidden || option.dataset.homeUnavailable === '1';
            if (option.disabled && option.selected) valid = false;
        });
        if (!valid) service.value = '';
    };
    familyFilter.addEventListener('change', () => {
        const previousService = service.value;
        filterFamilies();
        if (previousService !== service.value) service.dispatchEvent(new Event('change', { bubbles: true }));
    });
    filterFamilies();

    const labelForDate = (value) => slots.find((item) => item.date === value)?.date_label || value;
    const filteredSlots = () => slots.filter((item) => {
        const home = service.closest('form').querySelector('[name="service_mode"]')?.value === 'home';
        const allowedTherapists = @json($homeSettings['therapist_ids']);
        if (home && item.therapist_id && !allowedTherapists.map(Number).includes(Number(item.therapist_id))) return false;
        const serviceMatches = service.value && String(item.service_id) === service.value;
        const branchMatches = ! branch.value || item.branch_id === null || String(item.branch_id) === branch.value;

        return serviceMatches && branchMatches;
    });

    const reset = (select, label) => {
        select.innerHTML = '';
        select.append(new Option(label, ''));
    };

    const renderDates = () => {
        const current = date.value || selected.date;
        const currentSlot = slot.value || selected.slotId;
        reset(date, service.value ? 'Pilih tanggal tersedia' : 'Pilih layanan dulu');
        reset(slot, 'Pilih tanggal dulu');
        dateValue.value = '';
        timeValue.value = '';

        const dates = [...new Set(filteredSlots().map((item) => item.date))];
        dates.forEach((value) => date.append(new Option(labelForDate(value), value)));
        if (dates.length === 0 && service.value) {
            reset(date, 'Belum ada tanggal tersedia');
            reset(slot, 'Belum ada jam tersedia');
            help.textContent = 'Belum ada slot tersedia untuk layanan/cabang ini. Silakan pilih layanan atau cabang lain.';
            updateRecap();
            return;
        }

        if (dates.includes(current)) {
            date.value = current;
        }
        renderTimes(currentSlot);
    };

    const renderAddons = () => {
        addonTarget.innerHTML = '';
        const rows = addonOptions[service.value] || [];
        if (rows.length === 0) {
            addonHelp.textContent = service.value ? 'Belum ada addon aktif untuk layanan ini.' : 'Pilih layanan untuk melihat addon yang tersedia.';
            return;
        }
        rows.forEach((item) => {
            const label = document.createElement('label');
            label.style.cssText = 'display:flex;gap:.55rem;align-items:center;border:1px solid rgba(var(--g-line-rgb), .65);border-radius:.9rem;padding:.75rem;background:rgba(255,255,255,.6);';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'addons[]';
            input.value = item.id;
            input.checked = selectedAddons.has(String(item.id));
            input.addEventListener('change', () => {
                if (input.checked) selectedAddons.add(input.value);
                else selectedAddons.delete(input.value);
                updateRecap();
            });
            label.append(input, document.createTextNode(item.label));
            addonTarget.append(label);
        });
        addonHelp.textContent = 'Durasi dan harga addon akan ditambahkan ke request booking.';
    };

    const renderTimes = (currentSlot = slot.value || selected.slotId) => {
        reset(slot, date.value ? 'Pilih jam tersedia' : 'Pilih tanggal dulu');
        dateValue.value = date.value || '';
        timeValue.value = '';

        filteredSlots()
            .filter((item) => item.date === date.value)
            .forEach((item) => {
                const option = new Option(`${item.start_time} - ${item.end_time} | ${item.branch_name} | ${item.therapist_name} | sisa ${item.remaining}`, item.id);
                option.dataset.startTime = item.start_time;
                slot.append(option);
            });

        if ([...slot.options].some((option) => option.value === String(currentSlot))) {
            slot.value = String(currentSlot);
        }
        syncHiddenTime();
    };

    const syncHiddenTime = () => {
        const selectedOption = slot.selectedOptions[0];
        timeValue.value = selectedOption?.dataset.startTime || '';
        help.textContent = slot.value
            ? 'Tanggal dan jam akan mengikuti slot admin yang dipilih.'
            : 'Jam mengikuti slot yang sudah diinput admin dan masih tersedia.';
        updateRecap();
    };

    service.addEventListener('change', () => {
        selected.date = null;
        selected.slotId = null;
        selectedAddons.clear();
        renderAddons();
        renderDates();
    });
    branch.addEventListener('change', () => {
        selected.date = null;
        selected.slotId = null;
        renderDates();
    });
    date.addEventListener('change', () => {
        selected.slotId = null;
        renderTimes();
    });
    slot.addEventListener('change', syncHiddenTime);
    mode?.addEventListener('change', renderDates);
    form.addEventListener('home-quote-change', event => {
        homeTotal = event.detail;
        updateRecap();
    });

    renderAddons();
    renderDates();
})();
</script>
