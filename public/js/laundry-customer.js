(() => {
  'use strict';
  const access = document.querySelector('[data-portal-access]');
  if (access) {
    const token = window.location.hash.slice(1);
    if (/^[A-Za-z0-9]{64}$/.test(token)) {
      window.history.replaceState(null, '', window.location.pathname);
      access.elements.token.value = token;
      access.requestSubmit();
    }
  }
  const booking = document.querySelector('[data-public-laundry]');
  if (!booking) return;
  const lines = booking.querySelector('[data-lines]');
  const template = document.querySelector('[data-line-template]');
  const add = booking.querySelector('[data-add-line]');
  const delivery = booking.querySelector('input[type="checkbox"][name="delivery_required"]');
  const address = booking.querySelector('[data-address]');
  const refreshLines = () => {
    [...lines.children].forEach((line, index) => {
      line.querySelectorAll('[name]').forEach(field => {
        field.name = field.name.replace(/^items\[\d+\]/, `items[${index}]`);
      });
      line.querySelector('[data-remove-line]').disabled = lines.children.length === 1;
    });
    add.disabled = lines.children.length >= 10;
  };
  add.addEventListener('click', () => {
    if (lines.children.length >= 10) return;
    lines.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(lines.children.length)));
    refreshLines();
    lines.lastElementChild.querySelector('select').focus();
  });
  lines.addEventListener('click', event => {
    const button = event.target.closest('[data-remove-line]');
    if (!button || lines.children.length < 2) return;
    const line = button.closest('.line');
    const next = line.nextElementSibling || line.previousElementSibling;
    line.remove();
    refreshLines();
    next.querySelector('select').focus();
  });
  const sync = () => {
    const pickup = booking.elements.fulfilment.value === 'pickup';
    const shipping = pickup || delivery.checked;
    address.hidden = !shipping;
    address.querySelectorAll('input, textarea, button').forEach(field => { field.disabled = !shipping; });
    booking.elements.address.required = shipping;
    booking.elements.preferred_at.required = pickup;
  };
  booking.addEventListener('change', sync);
  refreshLines();
  sync();
  booking.querySelector('[data-locate]').addEventListener('click', () => {
    const status = booking.querySelector('[data-geo-status]');
    if (!navigator.geolocation) { status.textContent = 'Lokasi tidak tersedia. Isi alamat lengkap; ongkir dikonfirmasi admin.'; return; }
    status.textContent = 'Mencari titik lokasi…';
    navigator.geolocation.getCurrentPosition(position => {
      booking.elements.latitude.value = position.coords.latitude.toFixed(7);
      booking.elements.longitude.value = position.coords.longitude.toFixed(7);
      status.textContent = `Titik tersimpan (akurasi ±${Math.round(position.coords.accuracy)} meter). Periksa alamat dan koordinat sebelum melanjutkan.`;
    }, () => { status.textContent = 'Lokasi belum diperoleh. Isi alamat lengkap atau koordinat manual; admin akan mengonfirmasi ongkir.'; }, {enableHighAccuracy:true, timeout:15000, maximumAge:60000});
  });
})();
