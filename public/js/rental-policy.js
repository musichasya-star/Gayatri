document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-use-late-fee]').forEach(button => button.addEventListener('click', () => {
        const input = button.closest('form').querySelector('[name="late_fee"]');
        input.value = button.dataset.useLateFee;
        input.dispatchEvent(new Event('input', {bubbles: true}));
        input.focus();
    }));
});
