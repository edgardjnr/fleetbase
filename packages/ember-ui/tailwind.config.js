const paletaEntregas = require('./tailwind.paleta-entregas');

/** @type {import('tailwindcss').Config} */
module.exports = {
    darkMode: ['class', '[data-theme="dark"]'],
    content: [
        './app/**/*.{hbs,js}',
        './addon/**/*.{hbs,js}',
        './addon/**/*.css',
        // All Fleetbase packages (both pnpm symlinked and normal installs)
        './node_modules/@fleetbase+*/**/addon/**/*.{hbs,js}',
        './node_modules/@fleetbase/**/addon/**/*.{hbs,js}',
        // Explicit ember-ui paths
        './node_modules/@fleetbase/ember-ui/addon/templates/**/*.{hbs,js}',
        './node_modules/@fleetbase/ember-ui/addon/components/**/*.{hbs,js}',
        // Explicit support for .pnpm paths (for unhoisted deps)
        './node_modules/.pnpm/@fleetbase+*/**/addon/**/*.{hbs,js}',
        // Engine packages
        './node_modules/**/*-engine/addon/**/*.{hbs,js}',
    ],
    safelist: [
        {
            pattern: /(py|px|mx|my|gap)-[1-9][0-9]?/,
        },
    ],
    theme: {
        extend: {
            gridTemplateColumns: {
                span: ['span 1', 'span 2', 'span 3', 'span 4', 'span 5', 'span 6', 'span 7', 'span 8', 'span 9', 'span 10', 'span 11', 'span 12'],
            },
            ringColor: {
                DEFAULT: 'transparent',
            },
            // Entregas: gray, blue, sky, night, nightsky e moregray vêm da paleta do console (tailwind.paleta-entregas.js)
            colors: {
                ...paletaEntregas.colors,
                midnight: {
                    100: '#555555',
                    200: '#484848',
                    300: '#3b3b3b',
                    400: '#2e2e2e',
                    500: '#222222',
                    600: '#151515',
                    700: '#080808',
                },
            },
            boxShadow: {
                xs: '0 0 0 1px rgba(0,0,0, 0.05)',
                'light-xs': '0 0 0 1px rgba(212,220,236, 0.05)',
                'light-sm': '0 1px 2px 0 rgba(212,220,236, 0.05)',
                light: '0 1px 3px 0 rgba(212,220,236, 0.1), 0 1px 2px 0 rgba(212,220,236, 0.06)',
                'light-md': '0 4px 6px -1px rgba(212,220,236, 0.1), 0 2px 4px -1px rgba(212,220,236, 0.06)',
                'light-lg': '0 10px 15px -3px rgba(212,220,236, 0.1), 0 4px 6px -2px rgba(212,220,236, 0.05)',
                'light-xl': '0 20px 25px -5px rgba(212,220,236, 0.1), 0 10px 10px -5px rgba(212,220,236, 0.04)',
                'light-2xl': '0 25px 50px -12px rgba(212,220,236, 0.25)',
                'light-3xl': '0 35px 60px -15px rgba(212,220,236, 0.3)',
                pop: '0 0 2.25rem #d4dcec',
                'pop-less': '0 0 1rem #d4dcec',
                'pop-lesser': '0 0 .5rem #d4dcec',
                'pop-least': '0 0 .25rem #d4dcec',
                'dark-overlay': '-5px 10px 13px 3px rgba(0,0,0,0.3)',
                'dark-overlay-gray': '-5px 10px 13px 3px rgba(26, 32, 44, .5)',
                'overlay-inner': 'inset 0 1px 5px 0 rgba(0, 0, 0, 0.3)',
                'next-nav': 'rgba(0 0 0 / 35%) 0px 7px 32px',
            },
            width: {
                70: '18rem',
                74: '22rem',
                78: '26rem',
                82: '28rem',
                86: '30rem',
            },
            spacing: {
                70: '18rem',
                74: '22rem',
                78: '26rem',
                82: '28rem',
                86: '30rem',
            },
        },
    },
    variants: {
        boxShadow: ['responsive', 'hover', 'focus', 'group-focus', 'dark'],
        backgroundColor: ['responsive', 'hover', 'focus', 'dark', 'dark-hover', 'dark-group-hover', 'dark-even', 'dark-odd'],
        borderColor: ['responsive', 'hover', 'focus', 'dark', 'dark-disabled', 'dark-focus', 'dark-focus-within'],
        border: ['hover', 'focus', 'dark', 'dark-disabled', 'dark-focus'],
        textColor: ['responsive', 'hover', 'focus', 'dark', 'dark-hover', 'dark-active', 'dark-placeholder'],
        maxWidth: ['responsive', 'hover', 'focus'],
    },
    plugins: [require('@tailwindcss/forms')],
};
