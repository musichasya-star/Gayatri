@if($order->status === 'reserved')
    @php($checkoutCurrent = app(\App\Services\CRM\RentalCareService::class)->checkoutCurrent($item))
    <div class="rental-alert {{ $item->checklistLabels() && !$checkoutCurrent ? 'rental-error' : '' }}">
        @if($checkoutCurrent)Pemeriksaan sebelum keluar tersimpan {{ $item->checkout_inspected_at->format('d M Y H:i') }} WIB.
        @elseif($item->checklistLabels())Selesaikan checklist sebelum unit dapat diserahterimakan.
        @else Pemeriksaan rinci sebelum keluar dapat dicatat di bawah ini.@endif
    </div>
    <details @if(!$checkoutCurrent && $item->checklistLabels()) open @endif><summary>{{ $checkoutCurrent ? 'Periksa ulang kondisi sebelum keluar' : 'Pemeriksaan sebelum keluar' }}</summary>
        <form class="rental-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.rentals.care.checkout', [$order, $item]) }}">@csrf
            <input type="hidden" name="entry_key" value="{{ \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="care_version" value="{{ $item->unit->care_version }}">
            @include('admin.rentals.partials.checklist', ['checklistLabels' => $item->checklistLabels()])
            <label>Kondisi sebelum keluar {{ $item->unit_code }}<textarea class="input" name="notes" minlength="3" maxlength="3000" required>{{ $item->checkout_condition }}</textarea></label>
            <label>Foto sebelum keluar {{ $item->unit_code }} (opsional, maks. 5 MB)<input class="input" name="photo" type="file" accept="image/jpeg,image/png,image/webp"></label>
            <button class="button button-primary">Simpan Pemeriksaan {{ $item->unit_code }}</button>
        </form>
    </details>
@endif
