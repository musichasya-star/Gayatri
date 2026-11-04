@php
    // Main integration may pass $transportTasks to avoid this single order-scoped query.
    $orderTransportTasks = ($transportTasks ?? \App\Models\RentalTransportTask::with('assignedUser')->where('rental_order_id', $order->id)->get())->keyBy('type');
    $transportSchedulable = in_array($order->status, ['reserved', 'rented', 'inspection'], true);
@endphp
<div class="card"><div class="card-body">
    <h2>Jadwal Antar & Jemput</h2>
    <p><a class="button button-secondary" href="{{ route('admin.rentals.transport.index') }}">Lihat Jadwal Harian</a></p>
    @foreach(\App\Models\RentalTransportTask::TYPES as $transportType => $transportLabel)
        @php($transportTask = $orderTransportTasks->get($transportType))
        @if(in_array($order->fulfilment, [$transportType, 'both'], true) || $transportTask)
            <h3>{{ $transportLabel }}</h3>
            @if($transportTask)
                <p>{{ \App\Models\RentalTransportTask::STATUSES[$transportTask->status] }} · {{ $transportTask->assignedUser?->name ?? 'Petugas belum tersedia' }}<br>
                    {{ $transportTask->scheduled_start->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} — {{ $transportTask->scheduled_end->setTimezone('Asia/Jakarta')->format('d M Y H:i') }} WIB</p>
                @if($transportTask->isActive() && $transportTask->hasStaleSchedule($order))<p class="rental-alert rental-error">Kontrak berubah. Jadwalkan ulang penjemputan.</p>@endif
                @if($transportTask->isOverdue())<p class="rental-alert rental-error">Tugas melewati jendela jadwal.</p>@endif
                <a class="button button-secondary" href="{{ route('admin.rentals.transport.edit', [$order, $transportTask]) }}">{{ $transportTask->status === 'cancelled' && $transportSchedulable ? 'Jadwalkan Ulang' : 'Buka Tugas' }}</a>
            @elseif($transportSchedulable)
                <p>Belum ada jadwal {{ strtolower($transportLabel) }}.</p>
                <a class="button button-secondary" href="{{ route('admin.rentals.transport.create', ['order' => $order, 'type' => $transportType]) }}">Buat Jadwal {{ $transportLabel }}</a>
            @else
                <p>Jadwal dapat dibuat setelah pesanan dikonfirmasi.</p>
            @endif
        @endif
    @endforeach
    @if($order->fulfilment === 'self' && $orderTransportTasks->isEmpty())<p>Pesanan ini diambil dan dikembalikan sendiri oleh penyewa.</p>@endif
</div></div>
