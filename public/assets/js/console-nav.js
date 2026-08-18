/*
 |----------------------------------------------------------------------
 | Console sidebar drawer (mobile)
 |----------------------------------------------------------------------
 | Below the mobile breakpoint the sidebar becomes an off-canvas drawer
 | (see the console-shell rules in base/_breakpoints.css). This only ever
 | toggles a class -- the CSS owns what "open" looks like, including the
 | fact that none of this applies above the breakpoint at all.
 */

export function initConsoleNav() {
    const shell = document.querySelector('[data-console-shell]');
    if (!shell) return;

    const open = () => shell.classList.add('is-nav-open');
    const close = () => shell.classList.remove('is-nav-open');

    document.querySelectorAll('[data-console-nav-open]').forEach((btn) => {
        btn.addEventListener('click', open);
    });

    document.querySelectorAll('[data-console-nav-close]').forEach((btn) => {
        btn.addEventListener('click', close);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });

    // A nav link click navigates away, but on a same-page anchor or a
    // client-side-only interaction the drawer should still get out of the way.
    shell.querySelector('.console-sidebar-nav')?.addEventListener('click', (event) => {
        if (event.target.closest('a')) close();
    });
}
