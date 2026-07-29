/*
 |----------------------------------------------------------------------
 | Booking form
 |----------------------------------------------------------------------
 | Changing the date or duration refetches available start times and the
 | price from the server, so the customer never picks a slot that the
 | engine will then reject.
 |
 | The server remains the authority: every rule is re-checked in
 | BookingValidator when the form is submitted. This only removes the
 | round trip.
 */

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

export function initBookingForm() {
    const form = document.querySelector('[data-booking-form]');
    if (!form) return;

    const slotsUrl = form.dataset.slotsUrl;
    const slotContainer = form.querySelector('[data-slots]');
    const durationInput = form.querySelector('[data-duration]');
    const dateInput = form.querySelector('[data-date]');
    const totalEl = form.querySelector('[data-total]');
    const explanationEl = form.querySelector('[data-price-explanation]');
    const submitButton = form.querySelector('[data-submit]');

    if (!slotContainer) return;

    function setSubmitEnabled() {
        const chosen = slotContainer.querySelector('input[name="start_datetime"]:checked');
        if (submitButton) submitButton.disabled = !chosen;
    }

    function renderSlots(slots) {
        slotContainer.innerHTML = '';

        if (!slots.length) {
            const empty = document.createElement('p');
            empty.className = 'availability-none';
            // Say why there is nothing, and what to try next (SRS 9.16).
            empty.textContent =
                'No free times of this length on this date. Try a shorter booking or another day.';
            slotContainer.appendChild(empty);
            setSubmitEnabled();
            return;
        }

        slots.forEach((slot) => {
            const label = document.createElement('label');
            label.className = 'slot';

            const input = document.createElement('input');
            input.type = 'radio';
            input.name = 'start_datetime';
            input.value = slot.value;
            input.addEventListener('change', setSubmitEnabled);

            const time = document.createElement('span');
            time.textContent = slot.label;

            const ends = document.createElement('span');
            ends.className = 'slot-end';
            ends.textContent = `to ${slot.ends}`;

            label.append(input, time, ends);
            slotContainer.appendChild(label);
        });

        setSubmitEnabled();
    }

    /**
     * Placeholder slots while the real ones are being fetched.
     *
     * Dimming the previous day's times would be worse than useless -- they are
     * the wrong answer, shown confidently. Skeletons say "this is loading"
     * without ever displaying a time the customer could try to click.
     */
    function renderSkeletons(count = 12) {
        slotContainer.innerHTML = '';

        for (let i = 0; i < count; i++) {
            const placeholder = document.createElement('span');
            placeholder.className = 'slot is-skeleton skeleton';
            // Stagger so it reads as loading rather than as a solid block.
            placeholder.style.animationDelay = `${i * 40}ms`;
            slotContainer.appendChild(placeholder);
        }
    }

    let inFlight = null;

    async function refresh() {
        const params = new URLSearchParams({
            date: dateInput.value,
            duration: durationInput.value,
        });

        // Abandon an in-flight request so a fast clicker doesn't get an older
        // response landing after a newer one.
        inFlight?.abort();
        inFlight = new AbortController();

        slotContainer.setAttribute('aria-busy', 'true');
        renderSkeletons();

        try {
            const response = await fetch(`${slotsUrl}?${params}`, {
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                signal: inFlight.signal,
            });

            if (!response.ok) return;

            const data = await response.json();

            renderSlots(data.slots);
            if (totalEl) totalEl.textContent = data.total_label ?? totalEl.textContent;
            if (explanationEl) explanationEl.textContent = data.explanation;

            // The server may snap the duration onto a value the spot sells.
            // The duration is a hidden input driven by a segmented control,
            // and a hidden input rewritten in script fires nothing on its own,
            // so the buttons have to be told -- otherwise they keep showing
            // the length the customer asked for rather than the one they are
            // actually getting.
            //
            // Deliberately NOT a `change` event: this function is itself bound
            // to `change` on this input, so that would refetch, snap, and
            // dispatch again, forever.
            if (String(data.duration) !== durationInput.value) {
                durationInput.value = String(data.duration);
                durationInput.dispatchEvent(new CustomEvent('segmented:sync'));
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                slotContainer.innerHTML =
                    '<p class="availability-none">Could not load times. Please reload the page.</p>';
            }
        } finally {
            slotContainer.removeAttribute('aria-busy');
        }
    }

    durationInput?.addEventListener('change', refresh);
    dateInput?.addEventListener('change', refresh);

    slotContainer
        .querySelectorAll('input[name="start_datetime"]')
        .forEach((input) => input.addEventListener('change', setSubmitEnabled));

    setSubmitEnabled();
}
