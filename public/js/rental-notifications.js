document.addEventListener('DOMContentLoaded', () => {
    const settings = document.querySelector('[data-rental-notification-settings]');
    const form = document.querySelector('[data-rental-notification-preview]');
    if (!settings || !form) return;
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const output = form.querySelector('[data-notification-preview]'), button = form.querySelector('button');
        const data = new FormData(form), kind = data.get('kind');
        data.set('template', settings.querySelector(`[name="templates[${kind}]"]`).value);
        if (!data.get('template').trim()) {output.textContent = 'Isi template pesan terlebih dahulu.'; return;}
        button.disabled = true; output.textContent = 'Menyiapkan pratinjau…';
        try {
            const response = await fetch(form.dataset.previewUrl, {method:'POST', headers:{Accept:'application/json'}, body:data});
            const result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors).flat()[0] : result.message || 'Pratinjau tidak tersedia.');
            output.textContent = result.preview;
        } catch (error) {output.textContent = error.message || 'Koneksi gagal. Coba lagi.';}
        finally {button.disabled = false;}
    });
});
