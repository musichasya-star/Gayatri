document.addEventListener('DOMContentLoaded', function () {
    var guarded = Array.from(document.querySelectorAll('form[data-guard-unsaved]'));
    function values(form) {
        return JSON.stringify(Array.from(new FormData(form)).filter(function (entry) { return entry[0] !== '_token'; }).map(function (entry) {
            return [entry[0], typeof entry[1] === 'string' ? entry[1] : (entry[1].name ? [entry[1].name, entry[1].size, entry[1].lastModified] : null)];
        }));
    }
    var snapshots = guarded.map(values);
    var restored = guarded.map(function(form) { return form.hasAttribute('data-restored-draft'); });
    guarded.forEach(function (form, index) {
        var status = document.createElement('p');
        status.className = 'wb-draft'; status.hidden = true; status.setAttribute('role', 'status');
        status.textContent = 'Ada perubahan yang belum disimpan.';
        form.prepend(status);
        function update() {
            var dirty = restored[index] || values(form) !== snapshots[index];
            status.hidden = !dirty;
            var panel = form.closest('[data-landing-panel]');
            if (panel) {
                var tab = Array.from(document.querySelectorAll('[data-landing-tab]')).find(function(button) { return button.dataset.landingTab === panel.dataset.landingPanel; });
                if (tab) { tab.dataset.dirty = String(dirty || Array.from(panel.querySelectorAll('.wb-draft')).some(function(note) { return !note.hidden; })); tab.title = tab.dataset.dirty === 'true' ? 'Ada perubahan yang belum disimpan pada bagian ini.' : ''; }
            }
        }
        update();
        form.addEventListener('input', update); form.addEventListener('change', update);
        form.addEventListener('click', function () { requestAnimationFrame(update); });
        form.addEventListener('submit', function (event) { if (event.submitter && event.submitter.hasAttribute('data-ai-test')) { return; } snapshots[index] = values(form); restored[index] = false; status.hidden = true; });
    });
    window.addEventListener('beforeunload', function (event) {
        if (guarded.some(function (form, index) { return restored[index] || values(form) !== snapshots[index]; })) { event.preventDefault(); event.returnValue = ''; }
    });
    var provider = document.querySelector('[data-ai-provider]');
    var model = document.querySelector('[data-ai-model]');
    if (provider && model) {
        var aiForm = provider.form;
        var profiles = JSON.parse(aiForm.dataset.aiProviderSettings || '{}');
        var testResult = document.querySelector('[data-ai-test-result]');
        function models(changed) {
            Array.from(model.querySelectorAll('optgroup')).forEach(function (group) {
                var visible = group.label.toLowerCase() === provider.value.toLowerCase();
                group.hidden = !visible; group.disabled = !visible;
            });
            var keyField = document.querySelector('[data-ai-key-field]');
            if (keyField) {
                var keyInput = keyField.querySelector('input');
                keyField.hidden = provider.value === 'local'; keyInput.disabled = provider.value === 'local';
                keyInput.placeholder = profiles[provider.value] && profiles[provider.value].api_key_set ? 'API key provider ini sudah tersimpan. Isi untuk mengganti.' : 'Masukkan API key provider AI yang dipilih';
                if (changed) { keyInput.value = ''; }
            }
            if (changed) {
                var group = model.querySelector('optgroup:not([disabled])');
                var choices = group ? Array.from(group.querySelectorAll('option')) : [];
                var saved = profiles[provider.value] && profiles[provider.value].model;
                model.value = choices.some(function (option) { return option.value === saved; }) ? saved : (choices[0] ? choices[0].value : '');
                if (testResult) { testResult.hidden = true; }
            }
        }
        models(false); snapshots = guarded.map(values); provider.addEventListener('change', function () { models(true); model.dispatchEvent(new Event('change', { bubbles:true })); });
        if (testResult) {
            aiForm.addEventListener('input', function () { testResult.hidden = true; });
            aiForm.addEventListener('change', function () { testResult.hidden = true; });
            aiForm.addEventListener('submit', async function (event) {
                var button = event.submitter;
                if (!button || !button.hasAttribute('data-ai-test')) { return; }
                event.preventDefault();
                var snapshot = values(aiForm);
                var body = new FormData(aiForm);
                var buttons = Array.from(aiForm.querySelectorAll('button[type="submit"]'));
                buttons.forEach(function (control) { control.disabled = true; });
                testResult.hidden = false; testResult.setAttribute('role', 'status'); testResult.textContent = 'Memeriksa koneksi provider dan model yang dipilih…';
                try {
                    var response = await fetch(button.formAction, { method:'POST', credentials:'same-origin', headers:{ Accept:'application/json', 'X-Requested-With':'XMLHttpRequest' }, body:body });
                    if (response.redirected) { throw new Error('session'); }
                    var result = await response.json();
                    if (values(aiForm) !== snapshot) { testResult.textContent = 'Isian berubah saat pengujian berlangsung. Uji kembali pengaturan terbaru.'; return; }
                    var validation = result.errors ? Object.values(result.errors).flat()[0] : '';
                    testResult.textContent = (result.message || validation || 'Pengujian belum berhasil. Periksa isian provider dan model.') + (result.ok ? ' Hasil tes belum menyimpan perubahan. Klik Simpan AI untuk menerapkannya.' : '');
                    testResult.setAttribute('role', result.ok ? 'status' : 'alert');
                } catch (error) {
                    testResult.setAttribute('role', 'alert'); testResult.textContent = 'Pengujian belum selesai. Periksa koneksi atau muat ulang halaman jika sesi login berakhir.';
                } finally {
                    buttons.forEach(function (control) { control.disabled = false; }); testResult.hidden = false;
                }
            });
        }
    }
    document.querySelectorAll('[data-fee-example]').forEach(function (root) {
        function calculate() {
            var number = function (name) { return Number(root.querySelector('[name="example_'+name+'"]').value); };
            var base = Math.max(0, number('treatment') + number('addons') - number('discount'));
            var fee = Math.round(base * number('percent') / 100);
            var format = new Intl.NumberFormat('id-ID', { style:'currency', currency:'IDR', maximumFractionDigits:0 });
            root.querySelector('[data-fee-result]').textContent = Number.isFinite(fee) ? format.format(base) + ' × ' + number('percent') + '% = ' + format.format(fee) : 'Lengkapi angka contoh.';
        }
        root.addEventListener('input', calculate); calculate();
    });
});
