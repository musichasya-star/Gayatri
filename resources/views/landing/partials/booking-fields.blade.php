<label>
    <span class="label">Layanan</span>
    <select class="input" name="service_id" required>
        <option value="">Pilih layanan</option>
        @foreach($services as $service)
            <option value="{{ $service->id }}" @selected((string) old('service_id', $booking?->service_id) === (string) $service->id)>{{ $service->name }} - Rp {{ number_format((float) $service->price, 0, ',', '.') }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label">Cabang</span>
    <select class="input" name="branch_id">
        <option value="">Pilih cabang / ikuti layanan</option>
        @foreach($branches as $branch)
            <option value="{{ $branch->id }}" @selected((string) old('branch_id', $booking?->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
        @endforeach
    </select>
</label>
<label>
    <span class="label">Tanggal Treatment</span>
    <input class="input" type="date" name="booking_date" value="{{ old('booking_date', $booking?->booking_date?->format('Y-m-d') ?? now()->addDay()->toDateString()) }}" required>
</label>
<label>
    <span class="label">Jam Mulai</span>
    <input class="input" type="time" name="start_time" value="{{ old('start_time', $booking ? substr((string) $booking->start_time, 0, 5) : '10:00') }}" required>
</label>
<label style="grid-column:1/-1;">
    <span class="label">Catatan untuk Admin</span>
    <textarea class="input" name="notes" placeholder="Contoh: bayi usia 8 bulan, prefer terapis perempuan, atau request khusus lainnya.">{{ old('notes') }}</textarea>
</label>
