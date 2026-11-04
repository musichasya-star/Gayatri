const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const script = readFileSync('public/js/home-service.js', 'utf8');

class Element extends EventTarget {
    children = [];
    hidden = false;
    disabled = false;
    dataset = {};
    clientWidth = 600;
    clientHeight = 300;
    setAttribute(name, value) { this[name] = value; }
    append(...children) { this.children.push(...children); }
    after(element) { this.sibling = element; }
    emit(name) { this.dispatchEvent(new Event(name, {bubbles:true})); }
}
class Layer {
    events = {};
    on(name, callback) { this.events[name] = callback; return this; }
    addTo() { return this; }
    emit(name, data) { this.events[name]?.(data); }
}
function setup({hidden = false, leaflet = true} = {}) {
    const lat = new Element(), lng = new Element(), status = new Element();
    lat.value = '-7.8507897'; lng.value = '112.0384346';
    const container = new Element(), locate = new Element(), picker = new Element();
    container.dataset.errorTile = '/images/map-unavailable.svg';
    container.clientWidth = hidden ? 0 : 600;
    const fields = {'[data-lat]':lat, '[data-lng]':lng, '[data-location-status]':status, '[data-location-map]':container, '[data-locate]':locate};
    picker.querySelector = key => fields[key];
    let ready, visible, map, tiles, marker, geolocationCalls = 0, tileOptions, tileUrl;
    const L = {
        map() {
            map = new Layer();
            map.setView = (center, zoom) => { map.center = center; map.zoom = zoom; return map; };
            map.panTo = point => { map.center = point; };
            map.setZoom = zoom => { map.zoom = zoom; };
            map.invalidateSize = () => {};
            map.removeLayer = item => { item.removed = true; };
            return map;
        },
        tileLayer(url, options) { tileUrl = url; tileOptions = options; tiles = new Layer(); tiles.redraw = () => { tiles.redrawn = true; tiles.emit('loading'); }; return tiles; },
        marker(point, options) {
            marker = new Layer();
            marker.point = point; marker.options = options;
            marker.setLatLng = point => {marker.point = point;};
            marker.getLatLng = () => ({lat:marker.point[0],lng:marker.point[1]});
            return marker;
        },
    };
    runInNewContext(script, {
        Event, Intl,
        document: {
            addEventListener(name, fn) { if (name === 'DOMContentLoaded') ready = fn; },
            querySelectorAll(key) { return key === '[data-location-picker]' ? [picker] : []; },
            createElement() { return new Element(); },
        },
        window: {L:leaflet ? L : undefined, IntersectionObserver:class {}, ResizeObserver:class {}},
        L,
        IntersectionObserver:class {constructor(fn) {visible = fn;} observe() {} disconnect() {}},
        ResizeObserver:class {observe() {}},
        navigator:{geolocation:{getCurrentPosition(success) {geolocationCalls++; success({coords:{latitude:-7.85,longitude:112.03,accuracy:12}});}}},
    });
    ready();
    return {
        lat, lng, locate, container, status,
        get map() {return map;}, get marker() {return marker;}, get tiles() {return tiles;},
        get options() {return tileOptions;}, get url() {return tileUrl;},
        get calls() {return geolocationCalls;},
        show() {container.clientWidth = 600; visible([{isIntersecting:true}]);},
        intersect() {visible([{isIntersecting:true}]);},
    };
}

test('only visible maps load tiles, with origin-only referrer and visible OSM attribution', () => {
    const page = setup({hidden:true});
    assert.equal(page.tiles, undefined);
    page.intersect();
    assert.equal(page.tiles, undefined);
    page.show();
    assert.equal(page.url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    assert.equal(page.options.referrerPolicy, 'strict-origin');
    assert.match(page.options.attribution, /OpenStreetMap/);
    assert.equal(page.options.errorTileUrl, '/images/map-unavailable.svg');
    assert.equal(page.calls, 0);
});
test('map clicks and marker dragging update coordinates and notify the booking form', () => {
    const page = setup();
    let updates = 0;
    page.lat.addEventListener('input', () => updates++);
    page.lng.addEventListener('input', () => updates++);
    page.show();
    page.map.emit('click', {latlng:{lat:-7.8508,lng:112.0385}});
    assert.equal(page.lat.value, '-7.8508000');
    assert.equal(page.lng.value, '112.0385000');
    assert.equal(updates, 2);
    page.marker.point = [-7.8509,112.0386];
    page.marker.emit('dragend');
    assert.equal(page.lat.value, '-7.8509000');
    assert.equal(page.lng.value, '112.0386000');
    assert.equal(updates, 4);
});
test('manual coordinates update the pin and an incomplete point removes a stale pin', () => {
    const page = setup();
    page.show();
    page.lat.value = '-7.85';
    page.lng.value = '112.04';
    page.lng.emit('change');
    assert.deepEqual(Array.from(page.marker.point), [-7.85,112.04]);
    const marker = page.marker;
    page.lat.value = '';
    page.lat.emit('change');
    assert.equal(marker.removed, true);
    page.lat.value = '-7.84';
    page.lat.emit('change');
    assert.notEqual(page.marker, marker);
    assert.deepEqual(Array.from(page.marker.point), [-7.84,112.04]);
});
test('failed tiles expose manual fallback and retry recovers without changing the point', () => {
    const page = setup();
    page.show();
    page.tiles.emit('loading');
    page.tiles.emit('tileerror');
    page.tiles.emit('load');
    const hint = page.container.sibling;
    assert.equal(hint.hidden, false);
    const retry = hint.children[1];
    retry.emit('click');
    assert.equal(page.tiles.redrawn, true);
    assert.equal(retry.disabled, true);
    page.tiles.emit('load');
    assert.equal(hint.hidden, true);
    assert.equal(retry.disabled, false);
    assert.equal(page.lat.value, '-7.8507897');
    assert.equal(page.lng.value, '112.0384346');
});
test('geolocation is opt-in and manual fallback works if Leaflet cannot load', () => {
    const page = setup({leaflet:false});
    assert.match(page.status.textContent, /Peta gagal dimuat/);
    assert.equal(page.calls, 0);
    page.locate.emit('click');
    assert.equal(page.calls, 1);
    assert.equal(page.lat.value, '-7.8500000');
    assert.equal(page.lng.value, '112.0300000');
    assert.match(page.status.textContent, /akurasi/);
});
