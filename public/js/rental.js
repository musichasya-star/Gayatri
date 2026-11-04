document.addEventListener('DOMContentLoaded', () => {
    const money = value => 'Rp ' + new Intl.NumberFormat('id-ID', {maximumFractionDigits:0}).format(value);
    document.querySelectorAll('[data-rental-checkout]').forEach(form => {
        const summary = form.querySelector('[data-rental-summary]'), token = form.querySelector('[name="quote_token"]');
        const fulfilment = form.querySelector('[name="fulfilment"]'), address = form.querySelector('[data-rental-address]');
        const calculate = form.querySelector('[data-rental-calculate]'), itemList = form.querySelector('[data-rental-items]');
        const terms = form.querySelector('[data-rental-terms]'), accept = form.querySelector('[data-terms-accept]');
        const submit = form.querySelector('[data-rental-submit]');
        const recovery = form.querySelector('[data-rental-recovery]');
        const period = form.elements.period;
        let generation = 0, expiry = 0, loading = false, expiryTimer;
        const syncSubmit = () => {
            if (submit) submit.disabled = !token.value || loading || (accept && !accept.checked);
        };
        const invalidate = () => {
            clearTimeout(expiryTimer);
            generation++; token.value = ''; expiry = 0;
            summary.textContent = 'Pilihan berubah. Cek stok dan hitung ulang total sebelum memesan.';
            if (terms) terms.textContent = 'Hitung estimasi terbaru untuk membaca ketentuan pesanan.';
            if (accept) {accept.checked = false; accept.disabled = true;}
            if (recovery) recovery.hidden = true;
            syncSubmit();
        };
        const syncPeriods = () => {
            if (!submit) return;
            const selectedProducts = [...itemList.querySelectorAll('select')]
                .map(select => select.selectedOptions[0]).filter(option => option?.value);
            const allowed = [...period.options].filter(option => selectedProducts.every(product => product.dataset.periods.split(',').includes(option.value)));
            [...period.options].forEach(option => { option.disabled = !allowed.includes(option); });
            if (!allowed.some(option => option.value === period.value)) period.value = allowed[0]?.value || '';
            calculate.disabled = loading || allowed.length === 0;
            if (!allowed.length) summary.textContent = 'Alat yang dipilih tidak memiliki periode tarif yang sama. Ubah alat atau buat permintaan terpisah.';
        };
        const toggleAddress = () => {
            const needed = fulfilment.value !== 'self'; address.hidden = !needed;
            address.querySelectorAll('input,textarea,button').forEach(e => e.disabled = !needed);
            ['address', 'latitude', 'longitude'].forEach(name => form.querySelector(`[name="${name}"]`).required = needed);
        };
        const renumber = () => itemList.querySelectorAll('[data-rental-row]').forEach((row, index) => {
            row.querySelector('select').name = `items[${index}][product_id]`;
            row.querySelector('input').name = `items[${index}][quantity]`;
            const remove = row.querySelector('[data-remove-rental-item]');
            if (remove) remove.disabled = itemList.children.length === 1;
        });
        form.querySelector('[data-add-rental-item]')?.addEventListener('click', () => {
            if (itemList.children.length >= 10) {summary.textContent = 'Maksimal 10 jenis alat per transaksi.'; return;}
            const clone = itemList.firstElementChild.cloneNode(true);
            clone.querySelector('select').value = ''; clone.querySelector('input').value = '1'; itemList.append(clone); renumber(); invalidate(); syncPeriods();
        });
        itemList.addEventListener('click', e => {
            const remove = e.target.closest('[data-remove-rental-item]');
            if (!remove) return;
            if (itemList.children.length > 1) {remove.closest('[data-rental-row]').remove(); renumber(); invalidate(); syncPeriods();}
        });
        form.addEventListener('input', e => {if (/^(items\[|start_date|end_date|period|fulfilment|latitude|longitude)/.test(e.target.name || '')) invalidate();});
        form.addEventListener('change', e => {
            if (/^(items\[|start_date|end_date|period|fulfilment|latitude|longitude)/.test(e.target.name || '')) invalidate();
            if (e.target === fulfilment) toggleAddress();
            if (e.target.closest('[data-rental-items]')) syncPeriods();
            if (e.target.name === 'start_date') form.querySelector('[name="end_date"]').min = e.target.value;
        });
        calculate.addEventListener('click', async () => {
            invalidate(); const version = generation;
            calculate.disabled = true; loading = true; summary.textContent = 'Memeriksa stok dan menghitung total…';
            try {
                const quoteInput = new FormData(form);
                ['name', 'email', 'whatsapp_number', 'notes', 'quote_token', 'terms_accepted'].forEach(key => quoteInput.delete(key));
                const response = await fetch(form.dataset.quoteUrl, {method:'POST', headers:{Accept:'application/json'}, body:quoteInput});
                const data = await response.json();
                if (version !== generation) return;
                if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat()[0] : data.message || 'Gagal memeriksa stok.');
                const q = data.quote; token.value = data.token; expiry = q.expires_at * 1000;
                if (submit) expiryTimer = setTimeout(() => {
                    invalidate();
                    summary.textContent = 'Estimasi sudah kedaluwarsa. Cek stok dan hitung ulang untuk melanjutkan.';
                }, Math.max(0, expiry - Date.now()));
                const p = q.terms_snapshot;
                if (terms && p) {
                    const c = p.cancellation, l = p.late_fee;
                    terms.textContent = p.text + '\n\n' + p.products.filter(row => row.terms).map(row => row.name + ': ' + row.terms).join('\n') +
                        `\n\nDenda per unit: ${money(l.daily_rate)}/hari kalender setelah toleransi ${l.grace_days} hari. Batas: ${l.cap ? money(l.cap) : 'maksimal sistem Rp 100.000.000'}. Dihitung sampai tanggal kembali aktual, ditinjau admin saat pemeriksaan.` +
                        `\nPotongan pembatalan: ${c.type === 'percent' ? c.value + '% biaya jasa sewa dan ongkir, di luar deposit' : c.type === 'fixed' ? money(c.value) + ' per pesanan' : 'tanpa potongan'}. Maksimal biaya jasa dan dana yang sudah diterima.` +
                        (c.free_days_before > 0 ? ` Gratis jika diajukan minimal ${c.free_days_before} hari kalender sebelum tanggal mulai.` : '') +
                        '\nPembatalan hanya sebelum serah-terima. Refund ditinjau admin setelah saldo dicocokkan; persetujuan belum berarti dana sudah dikembalikan.';
                    if (accept) accept.disabled = false;
                }
                summary.textContent = q.rows.map(row => `${row.name} × ${row.quantity}: ${money(row.unit_price * row.quantity)}`).join('\n') +
                    `\nDurasi ${q.days} hari · ${q.period_count} periode\nSewa: ${money(q.rental_subtotal)}\nAntar: ${money(q.delivery_fee)}\nJemput: ${money(q.collection_fee)}\nDeposit: ${money(q.deposit_total)}\nTOTAL: ${money(q.total)}` +
                    (q.route ? `\nJarak jalan satu arah: ${(q.route.distance_meters / 1000).toLocaleString('id-ID', {maximumFractionDigits:2})} km` : '') +
                    (q.payment_terms?.minimum_dp ? `\nDP minimum: ${money(q.payment_terms.minimum_dp)} (dari biaya jasa, di luar deposit)\nBatas DP maksimal ${q.payment_terms.dp_due_hours} jam setelah konfirmasi, mengikuti batas pelunasan bila lebih awal.` : '') +
                    (q.payment_terms?.require_full_payment ? '\nPelunasan wajib diverifikasi sebelum serah-terima.' : '') + '\nEstimasi berlaku 15 menit. Unit dialokasikan saat permintaan berhasil disimpan.';
            } catch (error) {
                if (version === generation) {
                    summary.textContent = error.message || 'Koneksi gagal. Silakan coba lagi.';
                    if (recovery) recovery.hidden = false;
                }
            } finally {
                loading = false;
                calculate.disabled = false;
                syncPeriods();
                syncSubmit();
            }
        });
        form.addEventListener('submit', e => {
            if (!token.value || Date.now() > expiry || loading) {e.preventDefault(); summary.textContent = 'Cek stok dan hitung total terbaru terlebih dahulu.'; calculate.focus();}
        });
        accept?.addEventListener('change', syncSubmit);
        form.elements.end_date.min = form.elements.start_date.value || form.elements.start_date.min;
        toggleAddress();
        renumber();
        syncPeriods();
        syncSubmit();
    });
});
