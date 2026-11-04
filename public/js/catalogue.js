document.querySelectorAll('[data-offer-card]').forEach((card) => {
    const select = card.querySelector('[data-offer-select]');
    const extra = card.querySelector('[data-offer-extra]');
    const updateLink = () => {
        const choice = select.selectedOptions[0];
        const action = card.querySelector('[data-offer-action]');
        const url = new URL(choice.dataset.url, location.origin);
        if (url.hostname === 'wa.me' && extra.value) {
            url.searchParams.set('text', (url.searchParams.get('text') || '') + ' Pilihan detail: ' + extra.value + '.');
        }
        action.href = url.href;
        const saved = new URL(location.href);
        saved.searchParams.set('selected_service', choice.value);
        saved.hash = card.id;
        history.replaceState(null, '', saved.href);
    };
    const render = () => {
        const choice = select.selectedOptions[0];
        card.querySelector('[data-offer-price]').textContent = choice.dataset.price;
        const kind = card.querySelector('[data-offer-kind]');
        if (kind) kind.textContent = choice.dataset.kind;
        const photo = card.querySelector('[data-offer-photo]');
        if (choice.dataset.image) {
            photo.src = choice.dataset.image;
            photo.hidden = false;
        } else {
            photo.removeAttribute('src');
            photo.hidden = true;
        }
        card.querySelector('[data-offer-duration]').textContent = choice.dataset.duration;
        card.querySelector('[data-offer-description]').textContent = choice.dataset.description;
        const options = JSON.parse(choice.dataset.options || '[]');
        extra.replaceChildren(new Option('Konfirmasi pilihan detail dengan admin', ''));
        options.forEach(label => extra.append(new Option(label, label)));
        card.querySelector('[data-offer-extra-label]').hidden = options.length === 0;
        card.querySelector('[data-offer-options]').hidden = true;
        card.querySelector('[data-offer-note]').hidden = choice.dataset.admin !== '1';
        const action = card.querySelector('[data-offer-action]');
        action.href = choice.dataset.url;
        action.textContent = choice.dataset.admin === '1' ? 'Konfirmasi ke admin' : 'Pilih jadwal';
        const source = card.querySelector('[data-offer-source]');
        if (source) { source.href = choice.dataset.source; source.hidden = !choice.dataset.source; }
        const status = card.querySelector('[data-offer-status]');
        if (status) {
            status.textContent = choice.dataset.admin === '1' ? 'Konfirmasi admin' : 'Bisa pilih jadwal';
            status.dataset.ready = choice.dataset.admin === '1' ? '0' : '1';
        }
    };
    select.addEventListener('change', () => { render(); updateLink(); });
    extra.addEventListener('change', updateLink);
    render();
});
