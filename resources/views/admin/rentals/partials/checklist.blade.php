@if(count($checklistLabels))
    <fieldset class="rental-checklist"><legend>Checklist pemeriksaan</legend>
        @foreach($checklistLabels as $index => $label)
            <label>{{ $label }}<select class="input" name="checklist[{{ $index }}]" required><option value="">Pilih hasil pemeriksaan</option><option value="ok">Baik / lengkap</option><option value="issue">Bermasalah / belum lengkap</option></select></label>
        @endforeach
    </fieldset>
@else
    <p class="muted rental-small">Produk belum memiliki checklist khusus. Catat kondisi dan kelengkapan dengan rinci. Checklist dapat diatur melalui Katalog Rental.</p>
@endif
