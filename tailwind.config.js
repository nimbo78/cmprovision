const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');

/* Built with the standalone Tailwind CLI 3.4 (the forms and typography plugins are inside), see CLAUDE.md.
   The app's colours are the theme tokens of resources/css/app.css, as in t-premium-ui's preset: templates
   name roles (bg-panel, text-fg-muted, bg-neg-bg) and data-style/data-mode on <html> pick the values. */
const token = (name) => `var(--${name})`;

module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Jetstream 5's views carry dark: classes until they are converted to tokens (TokenGuardTest::NOT_YET)
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Tailwind 3 renamed v2's green, yellow and purple: the views not yet on tokens keep the v2 look
                green: colors.emerald,
                yellow: colors.amber,
                purple: colors.violet,
                page: token('page'), panel: token('panel'), pline: token('panel-line'), line: token('line'), head: token('head'),
                track: token('track'), link: token('link'), pop: token('pop'),
                fg: { DEFAULT: token('text'), muted: token('muted'), faint: token('faint') },
                accent: { DEFAULT: token('accent'), soft: token('accent-soft'), text: token('accent-text') },
                pos: { DEFAULT: token('pos-text'), bg: token('pos-bg'), bar: token('pos-bar') },
                neg: { DEFAULT: token('neg-text'), bg: token('neg-bg'), bar: token('neg-bar') },
                info: { DEFAULT: token('info-text'), bg: token('info-bg'), bar: token('info-bar') },
                warn: { DEFAULT: token('warn-text'), bg: token('warn-bg'), bar: token('warn-bar') },
                seg: { DEFAULT: token('seg'), text: token('seg-text'), on: token('seg-on'), ontext: token('seg-on-text') },
                log: { DEFAULT: token('log'), text: token('log-text'), muted: token('log-muted'), line: token('log-line'), err: token('log-err') },
            },
            boxShadow: {
                pop: token('pop-shadow'),
            },
            width: {
                '1/8': '12.5%',
            },
        },
    },

    plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')],
};
