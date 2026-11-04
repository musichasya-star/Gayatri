document.addEventListener('DOMContentLoaded', function () {
    var tableRegions = Array.from(document.querySelectorAll('.table-scroll, .table-responsive'));
    document.querySelectorAll('table.table-card').forEach(function (table) {
        if (table.closest('.table-scroll, .table-responsive')) return;
        var region = document.createElement('div');
        region.className = 'table-responsive';
        region.setAttribute('role', 'region');
        var caption = table.querySelector('caption');
        var heading = document.querySelector('h1');
        region.setAttribute('aria-label', (caption || heading) ? (caption || heading).textContent.trim() + ' — daftar data' : 'Daftar data');
        table.parentNode.insertBefore(region, table);
        region.appendChild(table);
        tableRegions.push(region);
    });
    tableRegions.forEach(function (region) {
        region.setAttribute('role', 'region');
        if (!region.hasAttribute('aria-label')) {
            var heading = document.querySelector('h1');
            region.setAttribute('aria-label', (heading ? heading.textContent.trim() : 'Daftar data') + ' — tabel dapat digeser');
        }
    });
    function updateTableRegions() {
        tableRegions.forEach(function (region) {
            if (region.scrollWidth > region.clientWidth + 2) region.setAttribute('tabindex', '0');
            else region.removeAttribute('tabindex');
        });
    }
    window.addEventListener('resize', updateTableRegions);
    window.addEventListener('load', updateTableRegions);
    document.addEventListener('toggle', updateTableRegions, true);
    updateTableRegions();

    var sidebar = document.querySelector('[data-sidebar]');
    var backdrop = document.querySelector('[data-sidebar-backdrop]');
    var toggles = document.querySelectorAll('[data-sidebar-toggle]');
    var panel = document.querySelector('.main-panel');
    var mobile = window.matchMedia('(max-width: 900px)');
    var opener = null;
    var previousOverflow = document.body.style.overflow;

    function setSidebar(open, restoreFocus) {
        if (!sidebar || !backdrop) {
            return;
        }

        open = open && mobile.matches;
        var wasOpen = sidebar.classList.contains('is-open');
        sidebar.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        sidebar.inert = mobile.matches && !open;
        if (panel) panel.inert = open;
        toggles.forEach(function (toggle) { toggle.setAttribute('aria-expanded', String(open)); });
        if (open) {
            sidebar.setAttribute('role', 'dialog');
            sidebar.setAttribute('aria-modal', 'true');
            sidebar.setAttribute('aria-label', 'Menu admin');
            if (!wasOpen) previousOverflow = document.body.style.overflow;
            document.body.style.overflow = 'hidden';
            var first = sidebar.querySelector('[aria-current="page"]') || sidebar.querySelector('button, a[href]');
            if (first) first.focus();
        } else {
            sidebar.removeAttribute('role');
            sidebar.removeAttribute('aria-modal');
            sidebar.removeAttribute('aria-label');
            if (wasOpen) document.body.style.overflow = previousOverflow;
            if (wasOpen && restoreFocus !== false && opener && opener.isConnected) opener.focus();
        }
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            if (!sidebar.classList.contains('is-open')) opener = toggle;
            setSidebar(!sidebar.classList.contains('is-open'));
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setSidebar(false);
        });
    }

    document.addEventListener('keydown', function (event) {
        if (!sidebar || !sidebar.classList.contains('is-open')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setSidebar(false);
        } else if (event.key === 'Tab') {
            var items = Array.from(sidebar.querySelectorAll('a[href], button:not([disabled]), [tabindex="0"]')).filter(function (item) { return item.getClientRects().length > 0; });
            var first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    if (sidebar) sidebar.addEventListener('click', function (event) {
        if (event.target.closest('a[href]') && mobile.matches) setSidebar(false, false);
    });
    function revealActiveMenu() {
        if (!sidebar || mobile.matches) return;
        var active = sidebar.querySelector('[aria-current="page"]');
        if (!active) return;
        var item = active.getBoundingClientRect(), bounds = sidebar.getBoundingClientRect();
        if (item.bottom > bounds.bottom - 16) sidebar.scrollTop += item.bottom - bounds.bottom + 16;
        else if (item.top < bounds.top + 16) sidebar.scrollTop += item.top - bounds.top - 16;
    }
    mobile.addEventListener('change', function () { setSidebar(false, false); revealActiveMenu(); });
    window.addEventListener('load', revealActiveMenu);
    setSidebar(false, false);
    revealActiveMenu();
});
