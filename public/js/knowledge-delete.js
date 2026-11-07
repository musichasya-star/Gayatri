(function () {
    'use strict';
    var dialog = document.querySelector('[data-knowledge-delete-dialog]');
    // The link still opens a server-rendered confirmation page if modal support is unavailable.
    if (!dialog || typeof dialog.showModal !== 'function') return;
    var form = dialog.querySelector('[data-knowledge-delete-form]');
    var title = dialog.querySelector('[data-knowledge-delete-name]');
    var cancel = dialog.querySelector('[data-knowledge-delete-cancel]');
    var confirmButton = dialog.querySelector('[data-knowledge-delete-confirm]');
    var opener = null;
    var sending = false;
    document.querySelectorAll('[data-knowledge-delete]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
            if (dialog.open) { event.preventDefault(); return; }
            title.textContent = link.dataset.knowledgeTitle;
            form.action = link.dataset.destroyUrl;
            form.elements.namedItem('revision').value = link.dataset.knowledgeRevision;
            try {
                dialog.showModal();
            } catch (_) {
                return;
            }
            event.preventDefault();
            opener = link;
            cancel.focus();
        });
    });
    cancel.addEventListener('click', function () { if (!sending) dialog.close(); });
    dialog.addEventListener('cancel', function (event) { if (sending) event.preventDefault(); });
    dialog.addEventListener('close', function () {
        if (opener && opener.isConnected) opener.focus();
        opener = null;
    });
    form.addEventListener('submit', function (event) {
        if (!dialog.open || !opener || sending) { event.preventDefault(); return; }
        sending = true;
        confirmButton.disabled = true;
        cancel.disabled = true;
        confirmButton.textContent = 'Menghapus…';
    });
})();
