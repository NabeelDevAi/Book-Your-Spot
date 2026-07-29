/*
 |----------------------------------------------------------------------
 | Scroll-reveal entrance animations
 |----------------------------------------------------------------------
 | Any element with [data-reveal] fades/slides in once it crosses the
 | viewport threshold. Plain IntersectionObserver -- no animation
 | library. The .reveal-ready class only gets added once we know JS is
 | running, so content never gets stuck invisible for no-JS/slow loads.
 */

export function initScrollReveal() {
    const targets = document.querySelectorAll('[data-reveal]');
    if (!targets.length) return;

    document.documentElement.classList.add('reveal-ready');

    if (!('IntersectionObserver' in window)) {
        targets.forEach((el) => el.classList.add('is-revealed'));
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-revealed');
                observer.unobserve(entry.target);
            });
        },
        { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );

    targets.forEach((el) => observer.observe(el));
}
