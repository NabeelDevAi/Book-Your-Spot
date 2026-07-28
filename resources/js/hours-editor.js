/*
 |----------------------------------------------------------------------
 | Operating hours editor
 |----------------------------------------------------------------------
 | Add/remove time ranges per weekday, toggle days closed, and copy one
 | day's hours to the whole week. Server-side validation is the authority
 | (App\Rules\ValidOperatingHours); this only makes the form usable.
 |
 | Input names follow operating_hours[mon][ranges][0][open], matching
 | OperatingHours::fromFormInput().
 */

function dayOf(element) {
    return element.closest('[data-hours-day]');
}

function reindex(dayEl) {
    const day = dayEl.dataset.hoursDay;

    dayEl.querySelectorAll('[data-hours-range]').forEach((range, index) => {
        range.querySelectorAll('input[type="time"]').forEach((input) => {
            const bound = input.dataset.bound; // "open" or "close"
            input.name = `operating_hours[${day}][ranges][${index}][${bound}]`;
        });
    });

    // The last remaining range can't be removed -- a day that is open needs at
    // least one range, and "no ranges" is expressed by marking it closed.
    const ranges = dayEl.querySelectorAll('[data-hours-range]');
    ranges.forEach((range) => {
        const remove = range.querySelector('[data-hours-remove]');
        if (remove) remove.hidden = ranges.length === 1;
    });
}

function markOvernight(range) {
    const open = range.querySelector('[data-bound="open"]')?.value;
    const close = range.querySelector('[data-bound="close"]')?.value;

    // A close time at or before the open time means the range runs past
    // midnight -- normal for these venues, so we confirm it rather than warn.
    const overnight = Boolean(open && close && close <= open);
    range.classList.toggle('is-overnight', overnight);
}

function addRange(dayEl) {
    const template = dayEl.querySelector('[data-hours-range]');
    if (!template) return;

    const clone = template.cloneNode(true);
    clone.classList.remove('is-overnight');
    clone.querySelectorAll('input[type="time"]').forEach((input) => {
        input.value = '';
    });

    dayEl.querySelector('[data-hours-ranges]').appendChild(clone);
    reindex(dayEl);
}

function setClosed(dayEl, closed) {
    dayEl.classList.toggle('is-closed', closed);

    // Time inputs are disabled rather than removed so the values survive a
    // mistaken toggle -- disabled inputs aren't submitted, and the server
    // treats a day with no ranges as closed anyway.
    dayEl.querySelectorAll('input[type="time"]').forEach((input) => {
        input.disabled = closed;
    });
}

function copyToAllDays(sourceDayEl, root) {
    const ranges = [...sourceDayEl.querySelectorAll('[data-hours-range]')].map((range) => ({
        open: range.querySelector('[data-bound="open"]').value,
        close: range.querySelector('[data-bound="close"]').value,
    }));

    const sourceClosed = sourceDayEl.classList.contains('is-closed');

    root.querySelectorAll('[data-hours-day]').forEach((dayEl) => {
        if (dayEl === sourceDayEl) return;

        const container = dayEl.querySelector('[data-hours-ranges]');
        const template = dayEl.querySelector('[data-hours-range]');
        if (!template) return;

        container.innerHTML = '';

        ranges.forEach((values) => {
            const clone = template.cloneNode(true);
            clone.querySelector('[data-bound="open"]').value = values.open;
            clone.querySelector('[data-bound="close"]').value = values.close;
            container.appendChild(clone);
            markOvernight(clone);
        });

        const closedToggle = dayEl.querySelector('[data-hours-closed]');
        if (closedToggle) closedToggle.checked = sourceClosed;
        setClosed(dayEl, sourceClosed);
        reindex(dayEl);
    });
}

export function initHoursEditor() {
    const editors = document.querySelectorAll('[data-hours-editor]');
    if (!editors.length) return;

    editors.forEach((root) => {
        root.querySelectorAll('[data-hours-day]').forEach((dayEl) => {
            const closedToggle = dayEl.querySelector('[data-hours-closed]');
            setClosed(dayEl, Boolean(closedToggle?.checked));
            dayEl.querySelectorAll('[data-hours-range]').forEach(markOvernight);
            reindex(dayEl);
        });

        root.addEventListener('click', (event) => {
            const add = event.target.closest('[data-hours-add]');
            if (add) {
                event.preventDefault();
                addRange(dayOf(add));
                return;
            }

            const remove = event.target.closest('[data-hours-remove]');
            if (remove) {
                event.preventDefault();
                const dayEl = dayOf(remove);
                remove.closest('[data-hours-range]').remove();
                reindex(dayEl);
                return;
            }

            const copy = event.target.closest('[data-hours-copy]');
            if (copy) {
                event.preventDefault();
                copyToAllDays(dayOf(copy), root);
            }
        });

        root.addEventListener('change', (event) => {
            if (event.target.matches('[data-hours-closed]')) {
                setClosed(dayOf(event.target), event.target.checked);
            }
        });

        root.addEventListener('input', (event) => {
            if (event.target.matches('input[type="time"]')) {
                markOvernight(event.target.closest('[data-hours-range]'));
            }
        });
    });
}
