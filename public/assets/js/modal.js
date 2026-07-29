/*
 |----------------------------------------------------------------------
 | Modals & destructive-action confirmation
 |----------------------------------------------------------------------
 | Built on native <dialog>, which gives focus trapping, Escape handling
 | and top-layer stacking for free.
 |
 | Open/close any dialog by id:
 |   <button data-modal-open="cancel-booking">Cancel</button>
 |   <dialog class="modal" id="cancel-booking"> ... <button data-modal-close> </dialog>
 |
 | Confirm a destructive form submission -- never window.confirm(), which
 | blocks the page and can't carry context:
 |   <form data-confirm="Cancel this reservation?"
 |         data-confirm-detail="The spot is released immediately."
 |         data-confirm-action="Yes, cancel it"
 |         data-confirm-tone="danger"> ...
 */

export function initModals() {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('[data-modal-open]');
        if (opener) {
            event.preventDefault();
            const dialog = document.getElementById(opener.dataset.modalOpen);
            if (dialog instanceof HTMLDialogElement) dialog.showModal();
            return;
        }

        const closer = event.target.closest('[data-modal-close]');
        if (closer) {
            event.preventDefault();
            closer.closest('dialog')?.close();
        }
    });

    // Clicking the backdrop closes the dialog. The dialog element itself
    // covers only the panel, so a click landing on <dialog> is a backdrop click.
    document.addEventListener('click', (event) => {
        if (event.target instanceof HTMLDialogElement && event.target.open) {
            event.target.close();
        }
    });
}

function buildConfirmDialog({ title, detail, action, tone }) {
    const dialog = document.createElement('dialog');
    dialog.className = 'modal modal-narrow';

    const toneClass = tone === 'warning' ? 'is-warning' : tone === 'info' ? 'is-info' : 'is-danger';
    const buttonClass = tone === 'danger' || !tone ? 'btn-danger' : 'btn-primary';

    const icon =
        '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">' +
        '<path d="M10 6.5v4m0 3h.01M8.6 2.6 1.7 14.4a1.6 1.6 0 0 0 1.4 2.4h13.8a1.6 1.6 0 0 0 1.4-2.4L11.4 2.6a1.6 1.6 0 0 0-2.8 0Z" ' +
        'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    dialog.innerHTML = `
        <div class="modal-header">
            <div>
                <div class="modal-icon ${toneClass}">${icon}</div>
                <h2 class="modal-title"></h2>
                <p class="modal-subtitle"></p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-confirm-cancel>Cancel</button>
            <button type="button" class="btn ${buttonClass}" data-confirm-accept></button>
        </div>
    `;

    // textContent, not innerHTML -- these strings come from the page and
    // may contain a business or customer name.
    dialog.querySelector('.modal-title').textContent = title;

    const subtitle = dialog.querySelector('.modal-subtitle');
    if (detail) {
        subtitle.textContent = detail;
    } else {
        subtitle.remove();
    }

    dialog.querySelector('[data-confirm-accept]').textContent = action || 'Confirm';

    return dialog;
}

export function initConfirmations() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.dataset.confirm || form.dataset.confirmed === 'true') return;

        event.preventDefault();

        const dialog = buildConfirmDialog({
            title: form.dataset.confirm,
            detail: form.dataset.confirmDetail,
            action: form.dataset.confirmAction,
            tone: form.dataset.confirmTone,
        });

        document.body.appendChild(dialog);

        dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => dialog.close());

        dialog.querySelector('[data-confirm-accept]').addEventListener('click', () => {
            form.dataset.confirmed = 'true';
            dialog.close();
            form.requestSubmit();
        });

        dialog.addEventListener('close', () => dialog.remove());
        dialog.showModal();
    });
}
