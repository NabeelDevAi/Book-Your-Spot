/*
 |----------------------------------------------------------------------
 | Dismissible alerts & auto-fading flash messages
 |----------------------------------------------------------------------
 */

function dismiss(alert) {
    alert.classList.add('is-leaving');
    alert.addEventListener('transitionend', () => alert.remove(), { once: true });
    // Fallback in case the element has no transition to fire.
    setTimeout(() => alert.remove(), 400);
}

export function initAlerts() {
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-alert-dismiss]');
        if (!button) return;
        const alert = button.closest('.alert');
        if (alert) dismiss(alert);
    });

    // Success flashes fade on their own; warnings and errors stay put until
    // the operator actually dismisses them.
    document.querySelectorAll('.alert[data-auto-dismiss]').forEach((alert) => {
        const delay = parseInt(alert.dataset.autoDismiss, 10) || 6000;
        setTimeout(() => dismiss(alert), delay);
    });
}
