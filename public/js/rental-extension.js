document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-rental-extension]').forEach(form => {
        const date = form.querySelector('[name="new_end_date"]'), token = form.querySelector('[name="extension_token"]');
        const summary = form.querySelector('[data-extension-summary]'), preview = form.querySelector('[data-preview-extension]'), save = form.querySelector('[data-save-extension]');
        let version = 0, expires = 0;
        const invalidate = () => {version++; token.value = ''; save.disabled = true; summary.textContent = 'Cek ulang jadwal dan biaya untuk tanggal pilihan.';};
        date.addEventListener('input', invalidate); date.addEventListener('change', invalidate);
        preview.addEventListener('click', async () => {
            invalidate(); const current = version;
            if (!date.value) {summary.textContent = 'Pilih tanggal kembali baru.'; return;}
            preview.disabled = true; summary.textContent = 'Memeriksa stok dan menghitung perpanjangan…';
            try {
                const response = await fetch(form.dataset.previewUrl, {method:'POST', headers:{Accept:'application/json'}, body:new FormData(form)});
                const data = await response.json();
                if (current !== version) return;
                if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat()[0] : data.message || 'Perpanjangan tidak tersedia.');
                const q = data.quote, money = v => 'Rp ' + new Intl.NumberFormat('id-ID').format(v);
                summary.textContent = `Tanggal kembali: ${q.old_end_date} → ${q.new_end_date}\nTambahan periode: ${q.extra_periods}\n` + q.rows.map(row => `${row.unit_code}: ${money(row.additional_charge)}`).join('\n') + `\nTOTAL TAMBAHAN: ${money(q.additional_charge)}\nEstimasi berlaku 10 menit. Jadwal diperiksa kembali saat disimpan.`;
                token.value = data.token; expires = q.expires_at * 1000; save.disabled = false;
            } catch (error) {if (current === version) summary.textContent = error.message || 'Koneksi gagal. Coba lagi.';}
            finally {preview.disabled = false;}
        });
        form.addEventListener('submit', e => {if (!token.value || Date.now() > expires) {e.preventDefault(); invalidate(); preview.focus();}});
    });
});
