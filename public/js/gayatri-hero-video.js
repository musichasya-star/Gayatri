(function () {
    'use strict';
    var hero = document.querySelector('[data-hero-motion]');
    if (!hero) return;
    var video = hero.querySelector('video');
    var button = hero.querySelector('[data-motion-toggle]');
    var label = hero.querySelector('[data-motion-label]');
    if (!video || !button || !video.canPlayType('video/mp4')) return;

    var portrait = window.matchMedia('(max-width: 700px)');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var connection = navigator.connection;
    var userPaused = false;
    var userStarted = false;
    var blocked = false;
    var visible = true;
    var generation = 0;
    // Keep the hero playback control hidden on desktop and mobile.
    video.muted = true;
    video.defaultMuted = true;

    function syncButton() {
        var playing = !video.paused && !video.ended;
        button.toggleAttribute('data-playing', playing);
        label.textContent = playing ? 'Jeda animasi' : 'Putar animasi';
    }
    function allowed() {
        return !userPaused && !blocked && !document.hidden && visible
            && (userStarted || (!reduced.matches && !(connection && connection.saveData)));
    }
    function source() {
        var url = portrait.matches ? video.dataset.mobileSrc : video.dataset.desktopSrc;
        if (video.getAttribute('src') !== url) {
            generation++;
            video.pause();
            hero.removeAttribute('data-video-ready');
            video.src = url;
            button.disabled = false;
            video.load();
        }
    }
    function resume() {
        if (!allowed()) { video.pause(); return; }
        source();
        var attempt = generation;
        var promise = video.play();
        if (promise && promise.catch) promise.catch(function (error) {
            if (attempt !== generation || error.name === 'AbortError') return;
            blocked = true;
            syncButton();
        });
    }
    video.addEventListener('playing', function () {
        // Recheck after loading: the user may have paused or changed tabs meanwhile.
        if (!allowed()) { video.pause(); return; }
        hero.setAttribute('data-video-ready', '');
        syncButton();
    });
    video.addEventListener('pause', syncButton);
    video.addEventListener('error', function () {
        hero.removeAttribute('data-video-ready');
        blocked = true;
        button.disabled = true;
        button.removeAttribute('data-playing');
        label.textContent = 'Animasi tidak tersedia';
    });
    button.addEventListener('click', function () {
        if (!video.paused) {
            userPaused = true;
            video.pause();
        } else {
            userPaused = false;
            userStarted = true;
            blocked = false;
            resume();
        }
    });
    portrait.addEventListener('change', function () {
        generation++;
        video.pause();
        hero.removeAttribute('data-video-ready');
        // Do not download the alternate movie if animation is paused.
        resume();
    });
    function preferenceChanged() {
        userStarted = false;
        resume();
    }
    reduced.addEventListener('change', preferenceChanged);
    if (connection && connection.addEventListener) connection.addEventListener('change', preferenceChanged);
    document.addEventListener('visibilitychange', resume);
    if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            visible = entries[0].isIntersecting;
            resume();
        }, {threshold: 0}).observe(hero);
    } else {
        resume();
    }
})();
