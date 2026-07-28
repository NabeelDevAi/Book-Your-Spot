/*
 |----------------------------------------------------------------------
 | Dropdown
 |----------------------------------------------------------------------
 | Replaces Breeze's Alpine dropdown. Event-delegated, so dropdowns added
 | to the DOM after load work without re-initialising anything.
 |
 | Markup:
 |   <div class="dropdown">
 |     <button data-dropdown-trigger aria-expanded="false">...</button>
 |     <div class="dropdown-menu align-end">...</div>
 |   </div>
 */

const OPEN_CLASS = 'is-open';

function closeAll(except = null) {
    document.querySelectorAll(`.dropdown.${OPEN_CLASS}`).forEach((dropdown) => {
        if (dropdown === except) return;
        dropdown.classList.remove(OPEN_CLASS);
        dropdown
            .querySelector('[data-dropdown-trigger]')
            ?.setAttribute('aria-expanded', 'false');
    });
}

function toggle(dropdown) {
    const isOpen = dropdown.classList.contains(OPEN_CLASS);
    closeAll(dropdown);
    dropdown.classList.toggle(OPEN_CLASS, !isOpen);
    dropdown
        .querySelector('[data-dropdown-trigger]')
        ?.setAttribute('aria-expanded', String(!isOpen));
}

export function initDropdowns() {
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-dropdown-trigger]');

        if (trigger) {
            event.preventDefault();
            const dropdown = trigger.closest('.dropdown');
            if (dropdown) toggle(dropdown);
            return;
        }

        // A click inside an open menu shouldn't close it unless the clicked
        // thing is itself an action (link or button), which will navigate anyway.
        const insideMenu = event.target.closest('.dropdown-menu');
        if (insideMenu && !event.target.closest('a, button')) return;

        closeAll();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeAll();
    });
}
