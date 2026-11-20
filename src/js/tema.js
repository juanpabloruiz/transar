'use strict';

(() => {
    const preference = window.matchMedia('(prefers-color-scheme: dark)');

    function applyTheme() {
        document.documentElement.dataset.bsTheme = preference.matches ? 'dark' : 'light';
    }

    applyTheme();
    preference.addEventListener('change', applyTheme);
})();
