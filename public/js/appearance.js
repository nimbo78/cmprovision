/*
 * Appearance: style (warm, neutral), density (compact, roomy) and theme (light, dark, auto) on <html> before the
 * page paints, Auto by sunrise and sunset where the server's time zone points (data-sun on <html>, as
 * t-premium-ui does for Moscow), and the Appearance popover in the top bar (Alpine: x-data="appearancePopover()").
 * Plain script (no build step): <head> runs it and gets window.Appearance; node tests require() its pure functions.
 */
(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        root.Appearance = api;
        api.start(root);
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var KEY = 'cmprovision-appearance';
    var DEFAULTS = { style: 'warm', density: 'compact', mode: 'auto' };
    var CHOICES = { style: ['warm', 'neutral'], density: ['compact', 'roomy'], mode: ['light', 'dark', 'auto'] };
    var DAY = 86400000;

    function rad(d) { return d * Math.PI / 180; }
    function deg(r) { return r * 180 / Math.PI; }
    function mod(a, n) { return ((a % n) + n) % n; }

    /* "Almanac for Computers" (t-premium-ui's sun.ts, ~2 min): the event's hour UTC on the UTC day of date, null without one */
    function eventHours(date, lat, lng, rising) {
        var lngHour = lng / 15;
        var start = Date.UTC(date.getUTCFullYear(), 0, 0);
        var day = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
        var t = Math.round((day - start) / DAY) + ((rising ? 6 : 18) - lngHour) / 24;
        var M = 0.9856 * t - 3.289;
        var L = mod(M + 1.916 * Math.sin(rad(M)) + 0.02 * Math.sin(rad(2 * M)) + 282.634, 360);
        var RA = mod(deg(Math.atan(0.91764 * Math.tan(rad(L)))), 360);
        RA = (RA + (Math.floor(L / 90) * 90 - Math.floor(RA / 90) * 90)) / 15;
        var sinDec = 0.39782 * Math.sin(rad(L));
        var cosDec = Math.cos(Math.asin(sinDec));
        var cosH = (Math.cos(rad(90.833)) - sinDec * Math.sin(rad(lat))) / (cosDec * Math.cos(rad(lat)));
        if (cosH > 1 || cosH < -1) return null;
        var H = (rising ? 360 - deg(Math.acos(cosH)) : deg(Math.acos(cosH))) / 15;
        return mod(H + RA - 0.06571 * t - 6.622 - lngHour, 24);
    }

    /* Sunrise and sunset on the UTC day of date at place {lat, lng}; null during polar day or night */
    function sunTimes(date, place) {
        var rise = eventHours(date, place.lat, place.lng, true);
        var set = eventHours(date, place.lat, place.lng, false);
        if (rise === null || set === null) return null;
        var midnight = Date.UTC(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate());
        return { sunrise: new Date(midnight + rise * 3600000), sunset: new Date(midnight + set * 3600000) };
    }

    function autoMode(now, place, prefersDark) {
        var sun = place ? sunTimes(now, place) : null;
        if (!sun) return prefersDark ? 'dark' : 'light';
        var day = sun.sunrise < sun.sunset
            ? now >= sun.sunrise && now < sun.sunset
            : now >= sun.sunrise || now < sun.sunset;   // the local day spans midnight UTC (far east and west)
        return day ? 'light' : 'dark';
    }

    function nextSwitch(now, place) {
        if (!place) return null;
        for (var i = 0; i < 3; i++) {
            var sun = sunTimes(new Date(now.getTime() + i * DAY), place);
            if (!sun) return null;
            var next = [sun.sunrise, sun.sunset].filter(function (t) { return t > now; }).sort(function (a, b) { return a - b; });
            if (next.length) return next[0];
        }
        return null;
    }

    function readChoice(text) {
        var saved;
        try { saved = JSON.parse(text || '{}'); } catch (e) { saved = null; }
        if (!saved || typeof saved !== 'object') saved = {};
        var choice = {};
        Object.keys(DEFAULTS).forEach(function (key) {
            choice[key] = CHOICES[key].indexOf(saved[key]) >= 0 ? saved[key] : DEFAULTS[key];
        });
        return choice;
    }

    function resolve(choice, now, place, prefersDark) {
        return { style: choice.style, density: choice.density, mode: choice.mode === 'auto' ? autoMode(now, place, prefersDark) : choice.mode };
    }

    var api = { DEFAULTS: DEFAULTS, readChoice: readChoice, sunTimes: sunTimes, autoMode: autoMode, nextSwitch: nextSwitch, resolve: resolve };

    /* In the browser: apply now (from <head>), then follow sunrise/sunset, the system theme and other tabs */
    api.start = function (win) {
        var html = win.document.documentElement;
        var media = win.matchMedia ? win.matchMedia('(prefers-color-scheme: dark)') : null;
        var listeners = [];
        var timer = null;

        function place() {
            var sun = (html.getAttribute('data-sun') || '').split(',');
            var lat = parseFloat(sun[0]), lng = parseFloat(sun[1]);
            return sun.length === 2 && isFinite(lat) && isFinite(lng) ? { lat: lat, lng: lng } : null;
        }
        function load() {
            try { return readChoice(win.localStorage.getItem(KEY)); } catch (e) { return readChoice(null); }
        }
        var choice = load();

        function apply() {
            var now = new Date(), where = place();
            var resolved = resolve(choice, now, where, !!(media && media.matches));
            html.setAttribute('data-style', resolved.style);
            html.setAttribute('data-density', resolved.density);
            html.setAttribute('data-mode', resolved.mode);
            if (timer) win.clearTimeout(timer);
            var next = choice.mode === 'auto' ? nextSwitch(now, where) : null;
            timer = next ? win.setTimeout(apply, Math.max(next - now, 0) + 1000) : null;
        }
        function notify() {
            listeners.forEach(function (fn) { fn(api.get()); });
        }

        apply();
        if (media && media.addEventListener) media.addEventListener('change', apply);
        win.addEventListener('storage', function (e) {
            if (e.key === KEY) { choice = load(); apply(); notify(); }
        });

        api.get = function () { return { style: choice.style, density: choice.density, mode: choice.mode }; };
        api.set = function (patch) {
            var next = api.get();
            Object.keys(patch || {}).forEach(function (key) { next[key] = patch[key]; });
            choice = readChoice(JSON.stringify(next));
            try { win.localStorage.setItem(KEY, JSON.stringify(choice)); } catch (e) { /* not remembered: this page only */ }
            apply();
            notify();
        };
        api.subscribe = function (fn) { listeners.push(fn); };
        api.today = function () { var where = place(); return where ? sunTimes(new Date(), where) : null; };
        api.city = function () { return html.getAttribute('data-sun-city'); };
        api.zone = function () { return html.getAttribute('data-sun-zone'); };

        win.appearancePopover = function () {
            return {
                open: false,
                choice: api.get(),
                init: function () {
                    var self = this;
                    api.subscribe(function (c) { self.choice = c; });
                },
                set: function (patch) {
                    api.set(patch);
                    this.choice = api.get();
                },
                close: function () {
                    if (!this.open) return;
                    this.open = false;
                    if (this.$refs && this.$refs.button) this.$refs.button.focus();
                },
                caption: function () {
                    var sun = api.today(), city = api.city(), zone = api.zone();
                    if (!sun || !city) return 'Auto: follows your system setting.';
                    var hm = function (d) {
                        try { return d.toLocaleTimeString('en-GB', { timeZone: zone || undefined, hour: '2-digit', minute: '2-digit' }); }
                        catch (e) { return d.toTimeString().slice(0, 5); }
                    };
                    return 'Auto: light from sunrise to sunset in ' + city + ', from the server’s time zone. Today '
                        + hm(sun.sunrise) + '–' + hm(sun.sunset) + '.';
                }
            };
        };
    };

    return api;
}));
