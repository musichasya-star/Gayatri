document.addEventListener('DOMContentLoaded', function () {
    var header = document.querySelector('[data-public-header]');
    var toggle = document.querySelector('[data-menu-toggle]');
    var menu = document.getElementById('public-menu');
    if (!header || !toggle || !menu || typeof menu.showModal !== 'function') return;
    header.dataset.menuReady = 'true';
    var previousOverflow = '';
    toggle.addEventListener('click', function () {
        previousOverflow = document.body.style.overflow;
        menu.showModal();
        document.body.style.overflow = 'hidden';
        toggle.setAttribute('aria-expanded', 'true');
    });
    menu.addEventListener('close', function () {
        document.body.style.overflow = previousOverflow;
        toggle.setAttribute('aria-expanded', 'false');
        if (toggle.getBoundingClientRect().width) toggle.focus({preventScroll:true});
    });
    menu.querySelector('[data-menu-close]').addEventListener('click', function () { menu.close(); });
    menu.addEventListener('keydown', function (event) {
        if (event.key !== 'Tab') return;
        var controls = Array.from(menu.querySelectorAll('button:not([disabled]), a[href]')).filter(function (control) { return control.getBoundingClientRect().width > 0; });
        var first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    menu.addEventListener('click', function (event) {
        var link = event.target.closest('a[href]');
        if (link) {
            menu.close();
            if (link.hash) requestAnimationFrame(function () {
                var target = document.getElementById(link.hash.slice(1));
                if (target) { target.setAttribute('tabindex', '-1'); target.focus({preventScroll:true}); }
            });
        } else if (event.target === menu) {
            var rect = menu.getBoundingClientRect();
            if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) menu.close();
        }
    });
    window.matchMedia('(min-width: 1000px)').addEventListener('change', function (event) { if (event.matches && menu.open) menu.close(); });
    function activeLink() {
        var current = window.location.hash || '#home';
        document.querySelectorAll('.gp-navigation a, .gp-menu nav a').forEach(function (link) {
            if (!link.hash) return;
            if (link.hash === current) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        });
    }
    window.addEventListener('hashchange', activeLink);
    activeLink();
});
