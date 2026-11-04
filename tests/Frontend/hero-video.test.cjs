const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const source = readFileSync('public/js/gayatri-hero-video.js', 'utf8');

class Element extends EventTarget {
    attrs = new Map();
    setAttribute(key, value) { this.attrs.set(key, value); }
    getAttribute(key) { return this.attrs.get(key) ?? null; }
    removeAttribute(key) { this.attrs.delete(key); }
    toggleAttribute(key, on) { on ? this.setAttribute(key, '') : this.removeAttribute(key); }
    emit(name) { this.dispatchEvent(new Event(name)); }
}
function setup({mobile = false, reduced = false, saveData = false, denied = false} = {}) {
    const button = new Element(), video = new Element(), hero = new Element();
    const label = {textContent: 'Putar animasi'};
    const document = new Element(), portrait = new Element(), motion = new Element(), connection = new Element();
    let visibility;
    const downloads = [];
    video.paused = true;
    video.dataset = {desktopSrc: '/desktop.mp4', mobileSrc: '/mobile.mp4'};
    video.canPlayType = () => 'probably';
    Object.defineProperty(video, 'src', {set(url) { video.setAttribute('src', url); }});
    video.load = () => downloads.push(video.getAttribute('src'));
    video.pause = () => { video.paused = true; video.emit('pause'); };
    video.play = () => {
        if (denied) return Promise.reject(Object.assign(new Error('User gesture needed'), {name: 'NotAllowedError'}));
        video.paused = false;
        video.emit('playing');
        return Promise.resolve();
    };
    hero.querySelector = selector => ({video, '[data-motion-toggle]': button, '[data-motion-label]': label})[selector];
    document.querySelector = () => hero;
    portrait.matches = mobile;
    motion.matches = reduced;
    connection.saveData = saveData;
    const window = {
        matchMedia: query => query.includes('max-width') ? portrait : motion,
        IntersectionObserver: class {},
    };
    runInNewContext(source, {
        document, window, navigator: {connection},
        IntersectionObserver: class {constructor(callback) {visibility = callback;} observe() {visibility([{isIntersecting:true}]);}},
    });
    return {video, button, label, downloads, document, portrait, motion, hero,
        visibility(on) {visibility([{isIntersecting:on}]);},
        allowPlayback() {denied = false;},
    };
}

test('loads only the matching film and switches orientation without losing manual pause', () => {
    const page = setup({mobile:true});
    assert.deepEqual(page.downloads, ['/mobile.mp4']);
    assert.equal(page.video.paused, false);
    assert.equal(page.label.textContent, 'Jeda animasi');
    page.button.emit('click');
    page.portrait.matches = false;
    page.portrait.emit('change');
    assert.equal(page.video.paused, true);
    assert.deepEqual(page.downloads, ['/mobile.mp4']);
    page.button.emit('click');
    assert.deepEqual(page.downloads, ['/mobile.mp4', '/desktop.mp4']);
    assert.equal(page.video.paused, false);
});
for (const preference of ['reduced', 'saveData']) {
    test(preference + ' keeps the poster without downloading until a visitor opts in', () => {
        const page = setup({[preference]: true});
        assert.deepEqual(page.downloads, []);
        page.button.emit('click');
        assert.equal(page.video.paused, false);
        assert.deepEqual(page.downloads, ['/desktop.mp4']);
        page.motion.matches = true;
        page.motion.emit('change');
        assert.equal(page.video.paused, true);
    });
}
test('stops offscreen and in background tabs, while preserving the visitor pause choice', () => {
    const page = setup();
    page.visibility(false);
    assert.equal(page.video.paused, true);
    page.visibility(true);
    assert.equal(page.video.paused, false);
    page.document.hidden = true;
    page.document.emit('visibilitychange');
    assert.equal(page.video.paused, true);
    page.document.hidden = false;
    page.document.emit('visibilitychange');
    assert.equal(page.video.paused, false);
    page.button.emit('click');
    page.visibility(false);
    page.visibility(true);
    assert.equal(page.video.paused, true);
});
test('autoplay denial permits a manual retry and video errors restore the poster', async () => {
    const page = setup({denied:true});
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(page.label.textContent, 'Putar animasi');
    page.allowPlayback();
    page.button.emit('click');
    assert.equal(page.video.paused, false);
    assert.equal(page.hero.attrs.has('data-video-ready'), true);
    page.video.emit('error');
    assert.equal(page.hero.attrs.has('data-video-ready'), false);
    assert.equal(page.button.disabled, true);
    assert.equal(page.label.textContent, 'Animasi tidak tersedia');
});
