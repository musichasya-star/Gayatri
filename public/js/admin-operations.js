document.addEventListener('DOMContentLoaded', function () {
    document.addEventListener('invalid', function (event) {
        var parent = event.target.closest('details');
        while (parent) { parent.open = true; parent = parent.parentElement.closest('details'); }
    }, true);
    function openSection(id) {
        var section = document.getElementById(id);
        if (!section) return;
        if (section.tagName === 'DETAILS') section.open = true;
        var parent = section.parentElement.closest('details');
        while (parent) { parent.open = true; parent = parent.parentElement.closest('details'); }
    }
    document.querySelectorAll('[data-record-nav] a[href^="#"]').forEach(function (link) { link.addEventListener('click', function () { openSection(link.hash.slice(1)); }); });
    if (location.hash) { try { openSection(decodeURIComponent(location.hash.slice(1))); } catch (_) {} }
    document.querySelectorAll('[data-home-selector]').forEach(function (root) {
        var search = root.querySelector('[data-choice-search]');
        var group = root.querySelector('[data-choice-group-filter]');
        var selectedOnly = root.querySelector('[data-choice-selected]');
        var rows = Array.from(root.querySelectorAll('[data-choice-row]'));
        function update() {
            var term = search.value.trim().toLocaleLowerCase('id');
            rows.forEach(function (row) { row.hidden = !row.dataset.search.toLocaleLowerCase('id').includes(term) || (group && group.value && row.dataset.group !== group.value) || (selectedOnly && selectedOnly.checked && !row.querySelector('input').checked); });
            root.querySelectorAll('[data-choice-family]').forEach(function (family) {
                var visible = Array.from(family.querySelectorAll('[data-choice-row]')).some(function (row) { return !row.hidden; });
                family.hidden = !visible;
                if (term || (selectedOnly && selectedOnly.checked)) family.open = visible;
            });
            root.querySelector('[data-choice-count]').textContent = rows.filter(function (row) { return !row.hidden; }).length + ' hasil · ' + rows.filter(function (row) { return row.querySelector('input').checked; }).length + ' dipilih';
        }
        root.addEventListener('input', update); root.addEventListener('change', update);
        root.querySelectorAll('[data-choice-bulk]').forEach(function (button) { button.addEventListener('click', function () { rows.filter(function (row) { return !row.hidden; }).forEach(function (row) { row.querySelector('input').checked = button.dataset.choiceBulk === 'select'; }); update(); }); });
        update();
    });
    var inbox = document.querySelector('[data-inbox]');
    if (!inbox) return;
    function sizeInbox() {
        var available = window.innerHeight - Math.max(0, inbox.getBoundingClientRect().top) - 16;
        inbox.style.setProperty('--inbox-height', Math.max(360, available) + 'px');
    }
    sizeInbox();
    window.addEventListener('resize', sizeInbox);
    var messages = inbox.querySelector('.chat-messages');
    if (messages) messages.scrollTop = messages.scrollHeight;
    var profile = document.querySelector('[data-inbox-profile]');
    var profileOpener = document.querySelector('[data-profile-open]');
    if (profile && profileOpener) {
        profileOpener.addEventListener('click', function () { profile.showModal(); });
        profile.querySelector('[data-profile-close]').addEventListener('click', function () { profile.close(); });
        profile.addEventListener('close', function () { profileOpener.focus(); });
        if (profile.hasAttribute('data-profile-errors')) profile.showModal();
    }
    var forms = Array.from(document.querySelectorAll('.page-content form'));
    function values(form) { return JSON.stringify(Array.from(new FormData(form)).filter(function (entry) { return entry[0] !== '_token'; })); }
    var initial = forms.map(values);
    var live = document.querySelector('[data-inbox-live]');
    var status = document.querySelector('[data-inbox-refresh-status]');
    try { live.checked = sessionStorage.getItem('gayatri.inbox.refresh') !== 'off'; } catch (_) {}
    live.addEventListener('change', function () { try { sessionStorage.setItem('gayatri.inbox.refresh', live.checked ? 'on' : 'off'); } catch (_) {} status.textContent = live.checked ? 'Pembaruan otomatis aktif.' : 'Pembaruan otomatis dijeda.'; });
    function dirty() { return forms.some(function (form, index) { return values(form) !== initial[index]; }); }
    function refresh(manual) {
        if (dirty()) { status.textContent = 'Pembaruan dijeda: masih ada draf atau isian yang belum disimpan.'; return; }
        if (!manual && (!live.checked || document.hidden || (profile && profile.open) || inbox.querySelector('details[open]') || document.activeElement.closest('input, textarea, select, .chat-messages') || (messages && messages.scrollHeight - messages.scrollTop - messages.clientHeight > 48))) { status.textContent = 'Pembaruan dijeda saat Anda bekerja atau membaca pesan lama.'; return; }
        window.location.reload();
    }
    document.querySelector('[data-inbox-refresh]').addEventListener('click', function () { refresh(true); });
    setInterval(function () { refresh(false); }, 15000);
    inbox.addEventListener('input', function () { if (dirty()) status.textContent = 'Draf tetap di halaman ini; pembaruan otomatis dijeda.'; });
});
