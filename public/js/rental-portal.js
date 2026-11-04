document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-payment-proof]').forEach(form => {
        const kind = form.querySelector('[data-proof-kind]'), amount = form.querySelector('[data-proof-amount]');
        kind.addEventListener('change', () => {amount.value = kind.value === 'dp' ? amount.dataset.dp : amount.dataset.balance;});
    });
});
