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
        form.addEventListener('submit', function () { snapshots[index] = values(form); restored[index] = false; status.hidden = true; });
    });
    window.addEventListener('beforeunload', function (event) {
        if (guarded.some(function (form, index) { return restored[index] || values(form) !== snapshots[index]; })) { event.preventDefault(); event.returnValue = ''; }
    });
    var provider = document.querySelector('[data-ai-provider]');
    var model = document.querySelector('[data-ai-model]');
    if (provider && model) {
        function models(changed) {
            Array.from(model.querySelectorAll('optgroup')).forEach(function (group) {
                var visible = group.label.toLowerCase() === provider.value.toLowerCase();
                group.hidden = !visible; group.disabled = !visible;
            });
            var keyField = document.querySelector('[data-ai-key-field]');
            if (keyField) { keyField.hidden = provider.value === 'local'; keyField.querySelector('input').disabled = provider.value === 'local'; }
            if (changed) { var first = model.querySelector('optgroup:not([disabled]) option'); model.value = first ? first.value : ''; }
        }
        models(false); snapshots = guarded.map(values); provider.addEventListener('change', function () { models(true); model.dispatchEvent(new Event('change', { bubbles:true })); });
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
