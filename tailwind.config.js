const defaultTheme = require('tailwindcss/defaultTheme');
const colors = require('tailwindcss/colors');

/* Built with the standalone Tailwind CLI 3.4 (the forms and typography plugins are inside), see CLAUDE.md */
module.exports = {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './vendor/laravel/jetstream/**/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    // Jetstream 5's views carry dark: classes; the app stays light whatever the operator's OS theme is
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Nunito', ...defaultTheme.fontFamily.sans],
            },
            // Tailwind 3 renamed v2's green, yellow and purple to emerald, amber and violet: keep the v2 look
            colors: {
                green: colors.emerald,
                yellow: colors.amber,
                purple: colors.violet,
            },
            width: {
                '1/8': '12.5%',
            },
        },
    },

    plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')],
};
