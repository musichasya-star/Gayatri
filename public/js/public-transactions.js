document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-transaction-submit]') || event.defaultPrevented) return;

    if (form.dataset.sending) {
        event.preventDefault();
        return;
    }

    form.dataset.sending = '1';
    form.setAttribute('aria-busy', 'true');
    const button = event.submitter;
    if (button) {
        button.setAttribute('aria-disabled', 'true');
        button.dataset.transactionSending = '1';
    }
    const status = form.querySelector('[data-transaction-progress]');
    if (status) status.textContent = 'Sedang memproses. Mohon tunggu.';
});

window.addEventListener('pageshow', () => {
    document.querySelectorAll('[data-transaction-submit][data-sending]').forEach(form => {
        delete form.dataset.sending;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('[data-transaction-sending]').forEach(button => {
            button.removeAttribute('aria-disabled');
            delete button.dataset.transactionSending;
        });
        const status = form.querySelector('[data-transaction-progress]');
        if (status) status.textContent = '';
    });
});
