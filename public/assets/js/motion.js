/*
 |----------------------------------------------------------------------
 | Motion helpers
 |----------------------------------------------------------------------
 | Three primitives the rest of the UI builds on, all feature-detected and
 | all no-ops when unsupported or when the user has asked for less motion:
 |
 |   transition(fn)  -- run a DOM mutation inside a view transition
 |   flip(items, fn) -- animate a list re-layout from its own geometry
 |   count-up        -- numbers count to their value when scrolled into view
 |
 | No animation library. The platform does all three natively now; pulling
 | in GSAP for this would be 30kb to replace about 60 lines.
 */

const reduced = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

/**
 * Run a DOM mutation inside a view transition so the browser cross-fades
 * (or morphs, where view-transition-name pairs elements) between states.
 */
let transitioning = false;

export function transition(mutate) {
    // Starting a transition while one is already running is where this gets
    // dangerous. The update callback runs asynchronously, and an aborted
    // transition can be torn down BEFORE its callback ever fires -- so the
    // DOM mutation is silently dropped, not merely un-animated. That showed
    // up as the theme cookie and the applied theme disagreeing after a few
    // fast clicks.
    //
    // So the mutation is never allowed to depend on the animation happening.
    // If anything is in flight, or the API is unavailable, or the user asked
    // for reduced motion, apply it directly and skip the visual.
    if (transitioning || reduced() || typeof document.startViewTransition !== 'function') {
        mutate();

        return Promise.resolve();
    }

    let view;

    try {
        view = document.startViewTransition(mutate);
    } catch {
        // Throws synchronously when the document is not fully active.
        mutate();

        return Promise.resolve();
    }

    transitioning = true;

    // The three promises a ViewTransition exposes reject independently.
    // Catching only `finished` leaves `ready` and `updateCallbackDone` to
    // surface as unhandled rejections -- which is what produced
    // "InvalidStateError: Transition was aborted because of invalid state"
    // in the console. None of them are actionable here.
    const swallow = () => {};

    view.ready.catch(swallow);
    view.updateCallbackDone.catch(swallow);

    return view.finished.catch(swallow).finally(() => {
        transitioning = false;
    });
}

/**
 * FLIP: measure First, mutate, measure Last, Invert the delta, then Play.
 *
 * Used when the result grid re-sorts or filters -- cards slide to their new
 * positions instead of teleporting, which is what makes a filter change feel
 * like a change rather than a page load.
 */
export function flip(elements, mutate, { duration = 400 } = {}) {
    const items = Array.from(elements);

    if (reduced() || ! items.length) {
        mutate();

        return;
    }

    const first = new Map(items.map((el) => [el, el.getBoundingClientRect()]));

    mutate();

    items.forEach((el) => {
        const before = first.get(el);
        const after = el.getBoundingClientRect();
        const dx = before.left - after.left;
        const dy = before.top - after.top;

        // Sub-pixel shifts are not worth a composited layer.
        if (Math.abs(dx) < 1 && Math.abs(dy) < 1) return;

        el.animate(
            [
                { transform: `translate(${dx}px, ${dy}px)` },
                { transform: 'translate(0, 0)' },
            ],
            { duration, easing: 'cubic-bezier(0.16, 1, 0.3, 1)' }
        );
    });
}

/**
 * Count a number up to its final value when it first scrolls into view.
 *
 * Opt in with data-count-up on an element whose text is already the final
 * value -- the server-rendered number stays the source of truth, so this
 * degrades to simply showing the correct figure if JS never runs.
 */
export function initCountUp() {
    const targets = document.querySelectorAll('[data-count-up]');

    if (! targets.length || reduced() || ! ('IntersectionObserver' in window)) return;

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (! entry.isIntersecting) return;

                observer.unobserve(entry.target);
                run(entry.target);
            });
        },
        { threshold: 0.4 }
    );

    targets.forEach((el) => observer.observe(el));

    function run(el) {
        const text = el.textContent.trim();
        // Preserve whatever wraps the digits -- "Rs. 4,000" and "12 spots"
        // both need their non-numeric parts left alone.
        const match = text.match(/[\d,]+/);

        if (! match) return;

        const target = parseInt(match[0].replace(/,/g, ''), 10);

        if (! Number.isFinite(target) || target === 0) return;

        const grouped = match[0].includes(',');
        const duration = Math.min(1100, 380 + target * 6);
        const start = performance.now();

        function frame(now) {
            const progress = Math.min(1, (now - start) / duration);
            // Expo-out, matching --ease-out so JS and CSS motion agree.
            const eased = 1 - Math.pow(1 - progress, 4);
            const value = Math.round(target * eased);

            el.textContent = text.replace(
                match[0],
                grouped ? value.toLocaleString('en-US') : String(value)
            );

            if (progress < 1) requestAnimationFrame(frame);
        }

        requestAnimationFrame(frame);
    }
}

/**
 * Opt the next navigation into a view transition and tag the element being
 * morphed, so the browser can pair it with its counterpart on the next page
 * (a venue card's crest becoming the venue page's hero).
 */
export function initViewTransitions() {
    if (reduced()) return;

    document.addEventListener('click', (event) => {
        const link = event.target.closest('[data-morph]');

        if (! link) return;

        document.querySelectorAll('[style*="view-transition-name"]').forEach((el) => {
            el.style.viewTransitionName = '';
        });

        const source = link.querySelector('[data-morph-target]') ?? link;
        source.style.viewTransitionName = 'venue-crest';
    });
}
