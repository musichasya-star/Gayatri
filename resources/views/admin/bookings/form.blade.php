@extends('layouts.app')

@section('title', ($booking->exists ? 'Edit Booking' : 'Tambah Booking') . ' - Gayatri CRM')

@section('content')
    <x-ui.page-header eyebrow="Booking" title="{{ $booking->exists ? 'Edit Booking' : 'Tambah Booking' }}" description="Sistem akan menolak jadwal yang bentrok untuk terapis yang sama.">
        <x-slot name="actions"><a class="button button-secondary" href="{{ route('admin.bookings.index') }}"><i data-lucide="arrow-left"></i> Kembali</a></x-slot>
    </x-ui.page-header>
    @php
        $selectedAddonIds = collect(old('addons', $booking->addOns?->pluck('service_addon_id')->filter()->values()->all() ?? []))->map(fn ($id) => (string) $id)->all();
        $addonOptions = $services->mapWithKeys(fn ($service) => [
            $service->id => $service->activeAddOns->map(fn ($addon) => [
                'id' => $addon->id,
                'name' => $addon->addonService?->name,
                'duration' => $addon->duration_minutes,
                'price' => (float) $addon->price_adjustment,
                'label' => ($addon->addonService?->name ?: 'Addon layanan').' +'.$addon->duration_minutes.' menit + Rp '.number_format((float) $addon->price_adjustment, 0, ',', '.'),
            ])->values(),
        ]);
    @endphp
    @if($errors->any())<div class="card" style="margin-bottom:1rem;border-color:rgba(201,122,106,.55);"><div class="card-body" style="color:#7a3228;">{{ $errors->first() }}</div></div>@endif
    <div class="card"><div class="card-body"><form method="POST" action="{{ $booking->exists ? route('admin.bookings.update', $booking) : route('admin.bookings.store') }}" class="grid grid-2">@csrf @if($booking->exists) @method('PUT') @endif
        <input type="hidden" name="conversation_id" value="{{ old('conversation_id', $booking->conversation_id) }}">
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Customer</span><select class="input" name="customer_id" required><option value="">Pilih customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(old('customer_id', $booking->customer_id)==$customer->id)>{{ $customer->name }} - {{ $customer->whatsapp_number }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Cabang</span><select class="input" name="branch_id"><option value="">Ikuti layanan</option>@foreach($branches as $branch)<option value="{{ $branch->id }}" @selected(old('branch_id', $booking->branch_id)==$branch->id)>{{ $branch->name }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Layanan</span><select class="input" name="service_id" id="booking-service" required><option value="">Pilih layanan</option>@foreach($services as $service)<option value="{{ $service->id }}" @selected(old('service_id', $booking->service_id)==$service->id)>{{ $service->name }} ({{ $service->duration_minutes }} menit)</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Terapis</span><select class="input" name="therapist_id"><option value="">Belum assign</option>@foreach($therapists as $therapist)<option value="{{ $therapist->id }}" @selected(old('therapist_id', $booking->therapist_id)==$therapist->id)>{{ $therapist->name }}</option>@endforeach</select></label>
        <div style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Addon / Tambah Layanan</span><div id="booking-addon-options" style="display:grid;gap:.5rem;"></div><span class="muted" id="booking-addon-help" style="display:block;margin-top:.35rem;">Pilih layanan utama untuk melihat addon yang tersedia.</span></div>
        <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Slot Jadwal Tersedia</span><select class="input" name="availability_slot_id"><option value="">Pilih otomatis berdasarkan tanggal/jam</option>@foreach($availabilitySlots as $slot)<option value="{{ $slot->id }}" @selected(old('availability_slot_id', $booking->availability_slot_id)==$slot->id)>{{ $slot->slot_date?->format('d M Y') }} {{ substr($slot->start_time,0,5) }}-{{ substr($slot->end_time,0,5) }} | {{ $slot->service?->name }} | {{ $slot->branch?->name ?: 'Semua cabang' }} | {{ $slot->therapist?->name ?: 'Terapis siapa saja' }} | {{ $slot->booked_count }}/{{ $slot->capacity }}</option>@endforeach</select><span class="muted" style="display:block;margin-top:.35rem;">Booking hanya bisa dibuat jika slot tersedia dan belum penuh.</span></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Promo</span><select class="input" name="promo_id"><option value="">Tanpa promo</option>@foreach($promos as $promo)<option value="{{ $promo->id }}" @selected(old('promo_id', $booking->promo_id)==$promo->id)>{{ $promo->title }} {{ $promo->code ? '('.$promo->code.')' : '' }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Tanggal</span><input class="input" type="date" name="booking_date" value="{{ old('booking_date', $booking->booking_date?->format('Y-m-d') ?? now()->toDateString()) }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Jam Mulai</span><input class="input" type="time" name="start_time" value="{{ old('start_time', substr((string)$booking->start_time,0,5) ?: '09:00') }}" required></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Status</span><select class="input" name="status" required>@foreach($statuses as $status)<option value="{{ $status }}" @selected(old('status', $booking->status)===$status)>{{ $status }}</option>@endforeach</select></label>
        <label><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Payment</span><select class="input" name="payment_status" required>@foreach($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', $booking->payment_status)===$status)>{{ $status }}</option>@endforeach</select></label>
        <label style="grid-column:1/-1;"><span class="muted" style="display:block;margin-bottom:.4rem;font-weight:700;">Catatan</span><textarea class="input" name="notes" rows="4">{{ old('notes', $booking->notes) }}</textarea></label>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:.75rem;"><a class="button button-secondary" href="{{ route('admin.bookings.index') }}">Batal</a><button class="button button-primary" type="submit"><i data-lucide="save"></i> Simpan</button></div>
    </form></div></div>
    <script>
        (() => {
            const options = @json($addonOptions);
            const selected = new Set(@json($selectedAddonIds));
            const service = document.getElementById('booking-service');
            const target = document.getElementById('booking-addon-options');
            const help = document.getElementById('booking-addon-help');

            const render = () => {
                target.innerHTML = '';
                const rows = options[service.value] || [];
                if (rows.length === 0) {
                    help.textContent = service.value ? 'Belum ada addon aktif untuk layanan ini.' : 'Pilih layanan utama untuk melihat addon yang tersedia.';
                    return;
                }
                rows.forEach((item) => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display:flex;gap:.6rem;align-items:center;border:1px solid rgba(216,195,165,.6);border-radius:.75rem;padding:.75rem;';
                    const input = document.createElement('input');
                    input.type = 'checkbox';
                    input.name = 'addons[]';
                    input.value = item.id;
                    input.checked = selected.has(String(item.id));
                    label.append(input, document.createTextNode(item.label));
                    target.append(label);
                });
                help.textContent = 'Durasi dan harga addon akan ditambahkan ke booking.';
            };

            service?.addEventListener('change', () => {
                selected.clear();
                render();
            });
            render();
        })();
    </script>
@endsection
