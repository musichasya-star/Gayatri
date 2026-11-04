@php($lateCalculation = $item->late_fee_calculation ?? app(\App\Services\CRM\RentalLateFee::class)->calculate($order, $item))
<div class="rental-summary"><strong>Kalkulator denda {{ $item->unit_code }}</strong><br>
    Jatuh tempo: {{ $lateCalculation['due_date'] }} · {{ $lateCalculation['actual_return'] ? 'Kembali' : 'Estimasi sampai hari ini' }}: {{ $lateCalculation['actual_date'] }}<br>
    Terlambat {{ $lateCalculation['late_days'] }} hari kalender · dikenakan {{ $lateCalculation['billable_days'] }} hari setelah toleransi.<br>
    @if($lateCalculation['policy'])Tarif Rp {{ number_format($lateCalculation['policy']['daily_rate'], 0, ',', '.') }}/hari/unit · batas {{ $lateCalculation['policy']['cap'] ? 'Rp '.number_format($lateCalculation['policy']['cap'], 0, ',', '.') : 'maksimal sistem Rp 100.000.000' }}.<br>@else Pesanan lama tanpa aturan denda tersimpan; masukkan hasil kesepakatan secara manual.<br>@endif
    <strong>Usulan: Rp {{ number_format($lateCalculation['suggested_fee'], 0, ',', '.') }}</strong><br>Denda baru masuk tagihan setelah pemeriksaan disimpan admin.
    @if($item->inspected_at)<br>Ditetapkan Rp {{ number_format($item->late_fee, 0, ',', '.') }} · {{ $lateCalculation['override_reason'] ?? 'Sesuai perhitungan' }}@endif
</div>
