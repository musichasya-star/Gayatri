document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('[data-service-form]');
    if (!form) return;
    var list = document.querySelector('[data-addon-list]');
    var template = document.querySelector('[data-addon-template]');
    var picker = document.querySelector('[data-addon-picker]');
    var search = picker.querySelector('[data-picker-search]');
    var results = picker.querySelector('[data-picker-results]');
    var choices = JSON.parse(document.getElementById('addon-catalogue-data').textContent);
    var targetRow = null;
    var returnFocus = null;
    function syncRows() {
        var rows = list.querySelectorAll('[data-addon-row]');
        rows.forEach(function (row, index) {
            row.querySelectorAll('[data-field]').forEach(function (field) { field.name = 'addons[' + index + '][' + field.dataset.field + ']'; });
        });
        document.querySelector('[data-addon-empty]').hidden = rows.length > 0;
    }
    function renderChoices() {
        var query = search.value.trim().toLocaleLowerCase('id');
        var group = form.elements.service_group.value;
        var used = Array.from(list.querySelectorAll('[data-addon-row]')).filter(function (row) { return row !== targetRow; }).map(function (row) { return row.querySelector('[data-field="addon_service_id"]').value; });
        var matches = choices.filter(function (choice) { return choice.group === group && !used.includes(String(choice.id)) && (choice.name + ' ' + (choice.code || '') + ' ' + choice.kind).toLocaleLowerCase('id').includes(query); });
        results.replaceChildren();
        picker.querySelector('[data-picker-count]').textContent = matches.length ? matches.length + ' pilihan. Menampilkan maksimal 20; ketik untuk mempersempit hasil.' : 'Tidak ada pilihan yang cocok pada kelompok layanan ini.';
        matches.slice(0, 20).forEach(function (choice) {
            var button = document.createElement('button');
            button.type = 'button'; button.className = 'button button-ghost';
            var title = document.createElement('strong'); title.textContent = choice.name;
            var detail = document.createElement('small'); detail.textContent = choice.kind + ' · ' + choice.duration + ' · ' + choice.price + (choice.code ? ' · ' + choice.code : '');
            button.append(title, detail);
            button.addEventListener('click', function () {
                targetRow.querySelector('[data-field="addon_service_id"]').value = choice.id;
                targetRow.querySelector('[data-field="addon_name"]').value = '';
                targetRow.querySelector('[data-choice-label]').textContent = choice.name;
                targetRow.querySelector('[data-choice-detail]').textContent = choice.duration + ' · ' + choice.price + '. Periksa biaya dan durasi tambahan di bawah.';
                targetRow.querySelector('[data-remove-addon]').setAttribute('aria-label', 'Lepaskan tambahan ' + choice.name);
                picker.close();
            });
            results.append(button);
        });
    }
    document.querySelector('[data-add-addon]').addEventListener('click', function () {
        if (list.querySelectorAll('[data-addon-row]').length >= 50) return;
        var row = template.content.firstElementChild.cloneNode(true); list.append(row); syncRows(); row.querySelector('[data-open-picker]').focus();
    });
    list.addEventListener('click', function (event) {
        var remove = event.target.closest('[data-remove-addon]');
        if (remove) { remove.closest('[data-addon-row]').remove(); syncRows(); document.querySelector('[data-add-addon]').focus(); return; }
        var open = event.target.closest('[data-open-picker]');
        if (open) { targetRow = open.closest('[data-addon-row]'); returnFocus = open; search.value = ''; renderChoices(); picker.showModal(); search.focus(); }
    });
    list.addEventListener('input', function (event) {
        if (event.target.dataset.field !== 'addon_name' || !event.target.value.trim()) return;
        var row = event.target.closest('[data-addon-row]'); row.querySelector('[data-field="addon_service_id"]').value = '';
        row.querySelector('[data-choice-label]').textContent = 'Tambahan khusus'; row.querySelector('[data-choice-detail]').textContent = '';
    });
    search.addEventListener('input', renderChoices);
    picker.querySelector('[data-close-picker]').addEventListener('click', function () { picker.close(); });
    picker.addEventListener('close', function () { if (returnFocus && returnFocus.isConnected) returnFocus.focus(); });
    function updateMode() {
        var restricted = ['package', 'addon'].includes(form.elements.catalog_kind.value) || Number(form.elements.package_quantity.value) > 1;
        form.elements.booking_mode.querySelector('[value="online"]').disabled = restricted;
        if (restricted) form.elements.booking_mode.value = 'admin';
        form.elements.price.required = form.elements.booking_mode.value === 'online';
        form.elements.duration_minutes.required = form.elements.booking_mode.value === 'online';
        form.querySelector('[data-mode-help]').textContent = restricted ? 'Paket dan tambahan tetap dikonfirmasi admin agar rangkaian layanan tidak dianggap satu booking biasa.' : 'Pilihan jadwal web memerlukan harga dan durasi. Ketersediaan mengikuti Jadwal Tersedia.';
    }
    form.elements.catalog_kind.addEventListener('change', updateMode);
    form.elements.booking_mode.addEventListener('change', updateMode);
    form.elements.package_quantity.addEventListener('input', updateMode);
    syncRows(); updateMode();
});
