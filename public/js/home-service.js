document.addEventListener('DOMContentLoaded', () => {
    const money = value => 'Rp ' + new Intl.NumberFormat('id-ID', {maximumFractionDigits: 0}).format(value);
    document.querySelectorAll('[data-location-picker]').forEach(picker => {
        const lat = picker.querySelector('[data-lat]'), lng = picker.querySelector('[data-lng]');
        const status = picker.querySelector('[data-location-status]');
        const container = picker.querySelector('[data-location-map]');
        const valid = () => lat.value.trim() !== '' && lng.value.trim() !== ''
            && Number.isFinite(Number(lat.value)) && Number.isFinite(Number(lng.value))
            && Math.abs(Number(lat.value)) <= 90 && Math.abs(Number(lng.value)) <= 180;
        let map, marker;
        const setPoint = (a, b) => {
            lat.value = Number(a).toFixed(7); lng.value = Number(b).toFixed(7);
            lat.dispatchEvent(new Event('input', {bubbles: true}));
            lng.dispatchEvent(new Event('input', {bubbles: true}));
            sync();
        };
        const sync = () => {
            if (!map) return;
            if (!valid()) {
                if (marker) { map.removeLayer(marker); marker = null; }
                return;
            }
            const point = [Number(lat.value), Number(lng.value)];
            if (!marker) {
                marker = L.marker(point, {draggable:true}).addTo(map);
                marker.on('dragend', () => {const p = marker.getLatLng(); setPoint(p.lat, p.lng);});
            } else marker.setLatLng(point);
            map.panTo(point);
        };
        const initializeMap = () => {
            if (map || !container.clientWidth || !container.clientHeight) return;
            const center = valid() ? [Number(lat.value), Number(lng.value)] : [Number(picker.dataset.centerLat || -7.816), Number(picker.dataset.centerLng || 112.012)];
            map = L.map(container, {scrollWheelZoom:false}).setView(center, 14);
            const tileStatus = document.createElement('p');
            tileStatus.className = 'home-map-status';
            tileStatus.setAttribute('role', 'status');
            tileStatus.hidden = true;
            const notice = document.createElement('span');
            notice.textContent = 'Gambar peta belum dapat dimuat. Koordinat tetap bisa diisi manual atau melalui tombol lokasi.';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.textContent = 'Muat ulang peta';
            tileStatus.append(notice, retry);
            container.after(tileStatus);
            let tileFailed = false;
            const tiles = L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom:19,
                // OSM requires a genuine Referer. Share only the origin, never CRM paths or tokens.
                // This applies only to map images; page-wide no-referrer protection stays in place.
                referrerPolicy:'strict-origin',
                updateWhenIdle:true,
                keepBuffer:1,
                errorTileUrl:container.dataset.errorTile,
                attribution:'&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
            });
            tiles.on('loading', () => { tileFailed = false; });
            tiles.on('tileerror', () => { tileFailed = true; tileStatus.hidden = false; });
            tiles.on('load', () => { tileStatus.hidden = !tileFailed; retry.disabled = false; });
            retry.addEventListener('click', () => { retry.disabled = true; tileStatus.hidden = true; tiles.redraw(); });
            tiles.addTo(map);
            map.on('click', e => setPoint(e.latlng.lat, e.latlng.lng));
            sync();
        };
        if (window.L) {
            // Closed home-service panels must not fetch invisible map tiles.
            if ('IntersectionObserver' in window) {
                const visibility = new IntersectionObserver(entries => {
                    if (entries.some(entry => entry.isIntersecting)) {
                        initializeMap();
                        if (map) { map.invalidateSize(); visibility.disconnect(); }
                    }
                });
                visibility.observe(container);
            } else initializeMap();
            if ('ResizeObserver' in window) {
                new ResizeObserver(() => { if (map) map.invalidateSize(); }).observe(container);
            }
        } else status.textContent = 'Peta gagal dimuat. Isi koordinat manual atau gunakan lokasi saya.';
        lat.addEventListener('change', sync); lng.addEventListener('change', sync);
        picker.querySelector('[data-locate]').addEventListener('click', () => {
            if (!navigator.geolocation) {status.textContent = 'Browser tidak mendukung lokasi. Pilih pin atau isi koordinat manual.'; return;}
            status.textContent = 'Meminta izin lokasi…';
            navigator.geolocation.getCurrentPosition(p => {setPoint(p.coords.latitude, p.coords.longitude); map?.setZoom(17); status.textContent = 'Lokasi diambil (akurasi ±' + Math.round(p.coords.accuracy) + ' meter). Periksa dan geser pin agar tepat di alamat kunjungan.';}, () => {status.textContent = 'Lokasi tidak tersedia atau izin ditolak. Klik peta atau isi koordinat manual.';}, {enableHighAccuracy:true, timeout:15000, maximumAge:0});
        });
    });
    document.querySelectorAll('[data-home-service]').forEach(root => {
        const form = root.closest('form'), mode = root.querySelector('[name="service_mode"]'), panel = root.querySelector('[data-home-panel]');
        const service = form.querySelector('[name="service_id"]'), therapist = form.querySelector('[name="therapist_id"]');
        const visitAddress = root.querySelector('[name="visit_address"]');
        const lat = root.querySelector('[data-lat]'), lng = root.querySelector('[data-lng]'), token = root.querySelector('[name="home_quote_token"]');
        const summary = root.querySelector('[data-home-summary]'), button = root.querySelector('[data-calculate]');
        const prices = JSON.parse(root.dataset.prices), addons = JSON.parse(root.dataset.addonPrices), allowed = JSON.parse(root.dataset.services).map(String), allowedTherapists = JSON.parse(root.dataset.therapists).map(String);
        let quote = null, generation = 0;
        const preserved = () => root.dataset.preserved === '1' && service.value === root.dataset.savedService && lat.value !== '' && lng.value !== '' && Number(lat.value) === Number(root.dataset.savedLat) && Number(lng.value) === Number(root.dataset.savedLng);
        const render = () => {
            if (mode.value !== 'home') {
                form.dispatchEvent(new CustomEvent('home-quote-change', {detail: null}));
                return;
            }
            const fee = quote?.travel_fee ?? (preserved() ? Number(root.dataset.savedFee) : null);
            if (fee === null) {
                summary.textContent = 'Pilih layanan dan pin tujuan, lalu hitung ongkos sebelum mengirim booking.';
                form.dispatchEvent(new CustomEvent('home-quote-change', {detail: null}));
                return;
            }
            const treatment = quote ? quote.treatment_price : (preserved() ? Number(root.dataset.savedPrice) : Number(prices[service.value] || 0));
            const extra = [...form.querySelectorAll('[name="addons[]"]:checked')].reduce((sum, e) => sum + Number(quote?.addon_prices?.[e.value] ?? addons[e.value] ?? 0), 0);
            const total = treatment + extra + fee;
            const routeDetails = quote
                ? `Jarak jalan satu arah ${ (quote.distance_meters / 1000).toLocaleString('id-ID', {maximumFractionDigits: 2}) } km · sekitar ${Math.ceil(quote.duration_seconds / 60)} menit`
                : 'Menggunakan ongkos yang tersimpan pada booking.';
            summary.textContent = [
                `Alamat kunjungan: ${visitAddress.value.trim() || 'Belum diisi'}`,
                routeDetails,
                `Layanan dan tambahan: ${money(treatment + extra)}`,
                `Ongkos perjalanan: ${money(fee)}`,
                `Total sebelum promo: ${money(total)}`,
                ...(quote ? ['Estimasi berlaku 15 menit.'] : []),
            ].join('\n');
            form.dispatchEvent(new CustomEvent('home-quote-change', {detail: total}));
        };
        const invalidate = () => {quote = null; token.value = ''; generation++; render();};
        const toggle = () => {
            const home = mode.value === 'home'; panel.hidden = !home;
            panel.querySelectorAll('input,textarea,button').forEach(e => e.disabled = !home);
            [lat, lng, root.querySelector('[name="visit_address"]')].forEach(e => e.required = home);
            [...service.options].forEach(option => {
                const unavailable = home && option.value !== '' && !allowed.includes(option.value)
                    && !(root.dataset.preserved === '1' && option.value === root.dataset.savedService);
                option.dataset.homeUnavailable = unavailable ? '1' : '0';
                option.disabled = option.hidden || unavailable;
            });
            if (therapist) [...therapist.options].forEach(e => e.disabled = home && e.value !== '' && !allowedTherapists.includes(e.value));
            if (service.selectedOptions[0]?.disabled) {service.value = ''; service.dispatchEvent(new Event('change', {bubbles:true}));}
            render();
        };
        mode.addEventListener('change', () => {invalidate(); toggle();});
        [lat,lng,service].forEach(e => e.addEventListener('input', invalidate));
        service.addEventListener('change', invalidate);
        visitAddress.addEventListener('input', render);
        form.addEventListener('change', render);
        button.addEventListener('click', async () => {
            invalidate();
            if (!service.value || !lat.value || !lng.value) {summary.textContent = 'Pilih layanan dan tentukan koordinat tujuan dahulu.'; return;}
            const version = generation;
            button.disabled = true; summary.textContent = 'Menghitung rute jalan dan ongkos…';
            try {
                const response = await fetch(root.dataset.quoteUrl, {method:'POST', headers:{'Accept':'application/json', 'Content-Type':'application/json', 'X-CSRF-TOKEN':form.querySelector('[name="_token"]').value}, body:JSON.stringify({service_id:service.value, latitude:lat.value, longitude:lng.value})});
                const data = await response.json();
                if (version !== generation) return;
                if (!response.ok) throw new Error(data.message || 'Gagal menghitung ongkos. Coba lagi.');
                quote = data.quote; token.value = data.token; render();
            } catch (error) {if (version === generation) summary.textContent = error.message || 'Koneksi gagal. Coba lagi.';}
            finally {button.disabled = mode.value !== 'home';}
        });
        form.addEventListener('submit', event => {
            if (mode.value === 'home' && ((!token.value && !preserved()) || (quote && quote.expires_at * 1000 < Date.now()))) {
                event.preventDefault(); summary.textContent = 'Hitung ongkos perjalanan terbaru terlebih dahulu.'; button.focus();
            }
        });
        toggle();
    });
});
