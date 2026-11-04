(() => {
    document.querySelector('[data-laundry-print]')?.addEventListener('click', () => window.print());
    document.querySelectorAll('[data-laundry-location]').forEach(section => {
        const button = section.querySelector('[data-laundry-locate]');
        const status = section.querySelector('[data-location-status]');
        button?.addEventListener('click', () => {
            if (!navigator.geolocation) {
                status.textContent = 'Perangkat ini tidak mendukung lokasi. Masukkan koordinat secara manual.';
                return;
            }
            button.disabled = true;
            status.textContent = 'Mengambil lokasi perangkat…';
            navigator.geolocation.getCurrentPosition(position => {
                section.querySelector('[data-latitude]').value = position.coords.latitude.toFixed(7);
                section.querySelector('[data-longitude]').value = position.coords.longitude.toFixed(7);
                status.textContent = 'Lokasi terisi. Periksa kembali titik dan alamat tujuan.';
                button.disabled = false;
            }, () => {
                status.textContent = 'Lokasi belum tersedia atau izin ditolak. Koordinat dapat diisi manual.';
                button.disabled = false;
            }, {enableHighAccuracy: true, timeout: 15000, maximumAge: 0});
        });
    });
    document.querySelectorAll('[data-laundry-attach]').forEach(form => {
        const select = form.querySelector('[name="laundry_order_id"]');
        const sync = () => { form.querySelector('[name="order_revision"]').value = select.selectedOptions[0]?.dataset.orderRevision || ''; };
        select.addEventListener('change', sync);
        sync();
    });
    document.querySelectorAll('[data-laundry-payment]').forEach(payment => {
        const method = payment.querySelector('[name="method"]');
        const syncPayment = () => {
            payment.querySelector('[name="reference"]').required = method.value === 'transfer';
            payment.querySelector('[name="proof"]').required = method.value === 'transfer';
        };
        method.addEventListener('change', syncPayment);
        syncPayment();
    });
    document.querySelectorAll('[data-laundry-quality]').forEach(quality => {
        const result = quality.querySelector('[name="result"]');
        const syncQuality = () => {
            const rework = result.value === 'rework';
            const notes = quality.querySelector('[name="notes"]');
            notes.required = rework;
            notes.minLength = rework ? 5 : 0;
            quality.querySelector('[name="restart_stage"]').required = rework;
        };
        result.addEventListener('change', syncQuality);
        syncQuality();
    });
    const unit = document.querySelector('[data-laundry-tariff-unit]');
    const updateUnit = () => {
        if (!unit) return;
        const form = unit.closest('form');
        const minimum = form.querySelector('[name="minimum_quantity"]');
        const increment = form.querySelector('[name="rounding_increment"]');
        minimum.step = unit.value === 'piece' ? '1' : '0.001';
        minimum.min = unit.value === 'piece' ? '1' : '0.001';
        increment.step = unit.value === 'piece' ? '1' : '0.001';
        increment.min = unit.value === 'piece' ? '1' : '0.001';
        if (unit.value === 'piece') increment.value = '1';
    };
    unit?.addEventListener('change', updateUnit);
    updateUnit();
    const form = document.querySelector('[data-laundry-intake]');
    if (!form) return;
    const list = form.querySelector('[data-laundry-lines]');
    const template = document.querySelector('[data-laundry-template]');
    const add = form.querySelector('[data-laundry-add]');
    const sync = () => {
        const rows = list.querySelectorAll('[data-laundry-line]');
        rows.forEach((row, index) => {
            row.querySelectorAll('[name]').forEach(field => {
                field.name = field.name.replace(/items\[[^\]]+\]/, `items[${index}]`);
            });
            row.querySelector('[data-laundry-remove]').disabled = rows.length <= 1;
            const selected = row.querySelector('[data-laundry-service]').selectedOptions[0];
            const piece = selected?.dataset.unit === 'piece';
            const estimate = row.querySelector('[data-laundry-estimate]');
            estimate.hidden = piece;
            estimate.querySelector('input').disabled = piece;
            row.querySelector('[name$="[received_pieces]"]').max = piece ? '1000' : '10000';
        });
        add.disabled = rows.length >= 20;
    };
    add.addEventListener('click', () => {
        if (list.querySelectorAll('[data-laundry-line]').length >= 20) return;
        list.append(template.content.cloneNode(true));
        sync();
    });
    list.addEventListener('change', sync);
    list.addEventListener('click', event => {
        const remove = event.target.closest('[data-laundry-remove]');
        if (remove && list.querySelectorAll('[data-laundry-line]').length > 1) {
            remove.closest('[data-laundry-line]').remove();
            sync();
        }
    });
    const customer = form.querySelector('[name="customer_id"]');
    const mode = form.querySelector('[name="intake_mode"]');
    const sections = () => {
        form.querySelectorAll('[data-laundry-new-customer]').forEach(label => {
            label.hidden = !!customer.value;
            label.querySelector('input').disabled = !!customer.value;
            label.querySelector('input').required = !customer.value;
        });
        const address = form.querySelector('[data-laundry-collection]');
        address.hidden = mode.value !== 'collection';
        address.querySelector('textarea').disabled = address.hidden;
        address.querySelector('textarea').required = !address.hidden;
    };
    customer.addEventListener('change', sections);
    mode.addEventListener('change', sections);
    sync(); sections();
})();
