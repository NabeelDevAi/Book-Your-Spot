/*
 |----------------------------------------------------------------------
 | Theme
 |----------------------------------------------------------------------
 | Three states, not two: light, dark, and "follow the OS" -- which is the
 | default and the one most theme switchers forget to offer.
 |
 | There is deliberately no flash-prevention script here. The preference
 | lives in a plain (unencrypted) cookie, so Blade renders data-theme onto
 | <html> server-side before a single byte of CSS is parsed. That is
 | strictly better than the usual inline-script trick: nothing to
 | mis-order, nothing to run, and it works with JS disabled.
 |
 | This module only handles the click: flip the attribute for the current
 | page, and persist the choice for the next one.
 */

import { transition } from './motion.js';

const COOKIE = 'theme';
const ORDER = ['system', 'light', 'dark'];
const ONE_YEAR = 60 * 60 * 24 * 365;

function readCookie() {
    const match = document.cookie.match(/(?:^|;\s*)theme=(light|dark)/);

    return match ? match[1] : 'system';
}

function persist(theme) {
    if (theme === 'system') {
        document.cookie = `${COOKIE}=; path=/; max-age=0; samesite=lax`;

        return;
    }

    document.cookie = `${COOKIE}=${theme}; path=/; max-age=${ONE_YEAR}; samesite=lax`;
}

function apply(theme) {
    const root = document.documentElement;

    if (theme === 'system') {
        delete root.dataset.theme;
    } else {
        root.dataset.theme = theme;
    }

    // Keep every control in sync -- the topbar and the console sidebar can
    // both be showing a toggle on the same page.
    document.querySelectorAll('[data-theme-toggle]').forEach((control) => {
        control.dataset.themeState = theme;

        control.querySelectorAll('[data-theme-option]').forEach((option) => {
            const selected = option.dataset.themeOption === theme;
            option.classList.toggle('is-active', selected);
            option.setAttribute('aria-checked', selected ? 'true' : 'false');
        });
    });
}

function resolved(theme) {
    return theme === 'system'
        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
        : theme;
}

export function initTheme() {
    let current = readCookie();

    apply(current);

    function set(theme) {
        if (! ORDER.includes(theme) || theme === current) return;

        current = theme;
        persist(theme);

        // Cross-fade the whole document rather than hard-cutting every surface
        // at once. transition() handles the unsupported, reduced-motion and
        // already-transitioning cases, and always applies the change.
        //
        // Note this applies `current`, not the captured `theme`. A view
        // transition runs its callback asynchronously, so on rapid clicks an
        // earlier transition's callback can fire AFTER a later click has
        // already applied its value -- reapplying the stale one and leaving
        // the cookie and the UI disagreeing. Reading the live value means
        // whichever callback lands last still settles on the right theme.
        transition(() => apply(current));
    }

    document.addEventListener('click', (event) => {
        const option = event.target.closest('[data-theme-option]');

        if (option) {
            event.preventDefault();
            set(option.dataset.themeOption);

            return;
        }

        // A plain toggle button with no explicit options cycles to the
        // opposite of whatever is currently showing.
        const toggle = event.target.closest('[data-theme-cycle]');

        if (toggle) {
            event.preventDefault();
            set(resolved(current) === 'dark' ? 'light' : 'dark');
        }
    });

    // While on "system", track the OS changing underneath us.
    window
        .matchMedia('(prefers-color-scheme: dark)')
        .addEventListener('change', () => {
            if (current === 'system') apply('system');
        });
}
