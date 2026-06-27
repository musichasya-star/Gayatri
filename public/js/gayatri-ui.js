document.addEventListener('DOMContentLoaded', function () {
    var sidebar = document.querySelector('[data-sidebar]');
    var backdrop = document.querySelector('[data-sidebar-backdrop]');
    var toggles = document.querySelectorAll('[data-sidebar-toggle]');

    function setSidebar(open) {
        if (!sidebar || !backdrop) {
            return;
        }

        sidebar.classList.toggle('is-open', open);
        backdrop.classList.toggle('is-open', open);
        document.body.style.overflow = open ? 'hidden' : '';
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            setSidebar(!sidebar.classList.contains('is-open'));
        });
    });

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            setSidebar(false);
        });
    }
});
