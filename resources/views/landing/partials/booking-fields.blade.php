@php
    $fieldId = $booking ? 'reschedule-booking' : 'new-booking';
    $selectedServiceId = (string) old('service_id', $booking?->service_id);
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
    ];
    $addonOptions = $services->mapWithKeys(fn ($service) => [
        $service->id => $service->activeAddOns->map(fn ($addon) => [
            'id' => $addon->id,
            'label' => ($addon->addonService?->name ?: 'Addon layanan').' +'.$addon->duration_minutes.' menit + Rp '.number_format((float) $addon->price_adjustment, 0, ',', '.'),
        ])->values(),
    ]);
@endphp

<label>
    <span class="label">Layanan</span>
    <select class="input" name="service_id" id="{{ $fieldId }}-service" required>
        <option value="">Pilih layanan</option>
        @foreach($services as $service)
            <option value="{{ $service->id }}" @selected($selectedServiceId === (string) $service->id)>{{ $service->name }} - Rp {{ number_format((float) $service->price, 0, ',', '.') }}</option>
        @endforeach
    </select>
</label>
<div style="grid-column:1/-1;">
    <span class="label">Addon / Tambah Layanan</span>
    <div id="{{ $fieldId }}-addons" style="display:grid;gap:.55rem;"></div>
    <span class="lead" id="{{ $fieldId }}-addon-help" style="display:block;margin:.35rem 0 0;font-size:.86rem;">Pilih layanan untuk melihat addon yang tersedia.</span>
</div>
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
    <span class="label">Tanggal Treatment</span>
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
<label style="grid-column:1/-1;">
    <span class="label">Catatan untuk Admin</span>
    <textarea class="input" name="notes" placeholder="Contoh: bayi usia 8 bulan, prefer terapis perempuan, atau request khusus lainnya.">{{ old('notes') }}</textarea>
</label>

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

    const labelForDate = (value) => slots.find((item) => item.date === value)?.date_label || value;
    const filteredSlots = () => slots.filter((item) => {
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
            return;
        }

        if (dates.includes(current)) {
            date.value = current;
        }
        renderTimes();
        renderAddons();
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
            label.style.cssText = 'display:flex;gap:.55rem;align-items:center;border:1px solid rgba(216,195,165,.65);border-radius:.9rem;padding:.75rem;background:rgba(255,255,255,.6);';
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.name = 'addons[]';
            input.value = item.id;
            input.checked = selectedAddons.has(String(item.id));
            label.append(input, document.createTextNode(item.label));
            addonTarget.append(label);
        });
        addonHelp.textContent = 'Durasi dan harga addon akan ditambahkan ke request booking.';
    };

    const renderTimes = () => {
        const currentSlot = slot.value || selected.slotId;
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
    };

    service.addEventListener('change', () => {
        selected.date = null;
        selected.slotId = null;
        selectedAddons.clear();
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

    renderDates();
})();
</script>
