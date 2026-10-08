/*
 * Upload progress arithmetic for the image upload form: percent, smoothed speed and remaining time.
 * Plain script (no build step): the browser gets window.UploadProgress, node tests require() it.
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.UploadProgress = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /*
     * Speed is the slope over the samples of the last windowMs milliseconds, so a stall or a
     * slowdown shows up within the window instead of being averaged over the whole upload.
     */
    function createTracker(options) {
        var windowMs = (options && options.windowMs) || 5000;
        var samples = [];

        return {
            update: function (loaded, total, now) {
                if (now === undefined) now = Date.now();
                samples.push({ t: now, loaded: loaded });
                while (samples.length > 2 && samples[0].t < now - windowMs) {
                    samples.shift();
                }

                var speed = null, eta = null;
                if (samples.length >= 2) {
                    var first = samples[0];
                    var elapsed = (now - first.t) / 1000;
                    speed = elapsed > 0 ? (loaded - first.loaded) / elapsed : 0;
                    eta = speed > 0 ? Math.round((total - loaded) / speed) : null;
                }

                return {
                    loaded: loaded,
                    total: total,
                    percent: total > 0 ? Math.min(100, Math.floor(loaded * 100 / total)) : 0,
                    speed: speed,
                    eta: eta
                };
            }
        };
    }

    function formatBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0, value = bytes;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        var digits = i === 0 ? 0 : (value >= 10 ? 1 : 2);
        return parseFloat(value.toFixed(digits)) + ' ' + units[i];
    }

    function formatDuration(seconds) {
        seconds = Math.round(seconds);
        if (seconds < 60) return seconds + ' s';
        if (seconds < 3600) return Math.floor(seconds / 60) + ' min ' + (seconds % 60) + ' s';
        return Math.floor(seconds / 3600) + ' h ' + Math.floor((seconds % 3600) / 60) + ' min';
    }

    return { createTracker: createTracker, formatBytes: formatBytes, formatDuration: formatDuration };
}));
