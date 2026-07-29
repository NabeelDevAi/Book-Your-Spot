/*
 |----------------------------------------------------------------------
 | Segmented control
 |----------------------------------------------------------------------
 | A row of buttons that writes to one hidden input.
 |
 | The hidden input is the point. Everything downstream -- form submission,
 | validation errors coming back from the server, and booking-form.js
 | reading `.value` to refetch slots -- keeps talking to a plain form
 | field and has no idea it is being driven by buttons. Swapping a
 | <select> for a custom control this way changed no other code.
 */

function sync(group, value) {
    group.querySelectorAll('[data-segmented-option]').forEach((option) => {
        const selected = option.dataset.segmentedOption === String(value);
        option.classList.toggle('is-active', selected);
        option.setAttribute('aria-checked', selected ? 'true' : 'false');
        // Roving tabindex: the group is one tab stop, arrows move within it.
        option.tabIndex = selected ? 0 : -1;
    });
}

export function initSegmented() {
    document.querySelectorAll('[data-segmented]').forEach((group) => {
        const input = document.querySelector(group.dataset.segmented);

        if (! input) return;

        sync(group, input.value);

        // A hidden input never fires anything on its own, so when something
        // else rewrites it -- the server snapping a duration onto a value the
        // spot actually sells -- it announces itself with this event and the
        // buttons follow.
        //
        // A dedicated event rather than `change`, because `change` on this
        // input is what triggers the slot refetch: reusing it would put the
        // two in a loop.
        input.addEventListener('segmented:sync', () => sync(group, input.value));

        group.addEventListener('click', (event) => {
            const option = event.target.closest('[data-segmented-option]');

            if (! option || option.dataset.segmentedOption === input.value) return;

            input.value = option.dataset.segmentedOption;
            sync(group, input.value);
            input.dispatchEvent(new Event('change', { bubbles: true }));
        });

        group.addEventListener('keydown', (event) => {
            const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];

            if (! keys.includes(event.key)) return;

            const options = [...group.querySelectorAll('[data-segmented-option]')];
            const current = options.findIndex((o) => o.dataset.segmentedOption === input.value);

            const next = {
                ArrowLeft: Math.max(0, current - 1),
                ArrowRight: Math.min(options.length - 1, current + 1),
                Home: 0,
                End: options.length - 1,
            }[event.key];

            event.preventDefault();
            options[next].click();
            options[next].focus();
        });
    });
}
