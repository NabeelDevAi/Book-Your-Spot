/*
 |----------------------------------------------------------------------
 | Notification bell
 |----------------------------------------------------------------------
 | Polls for unread notifications so an owner with the queue open sees a
 | new request arrive without refreshing.
 |
 | Polling rather than websockets: V1 runs a handful of venues on shared
 | hosting, and a 60-second poll against one indexed count query is far
 | cheaper to run and operate than a broadcast stack. Worth revisiting if
 | the platform grows.
 */

const POLL_INTERVAL = 60_000;

function icon(name) {
    // Mirrors the small subset of the Blade icon set the bell can render.
    const paths = {
        bell: '<path d="M15 7a5 5 0 1 0-10 0c0 5-2 6-2 6h14s-2-1-2-6M11.7 16a2 2 0 0 1-3.4 0"/>',
        'check-circle': '<circle cx="10" cy="10" r="7.5"/><path d="M6.5 10l2.5 2.5 4.5-5"/>',
        'x-circle': '<circle cx="10" cy="10" r="7.5"/><path d="M7.5 7.5l5 5M12.5 7.5l-5 5"/>',
        clock: '<circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2"/>',
        calendar: '<rect x="2.5" y="3.5" width="15" height="14" rx="2"/><path d="M2.5 8h15M6.5 1.5v4M13.5 1.5v4"/>',
        flag: '<path d="M4.5 17.5V3.5M4.5 4.2h9.8l-1.7 3.3 1.7 3.3H4.5"/>',
    };

    return `<svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor"
        stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        ${paths[name] ?? paths.bell}</svg>`;
}

export function initNotifications() {
    const bell = document.querySelector('[data-bell]');
    if (!bell) return;

    const badge = bell.querySelector('[data-bell-badge]');
    const list = document.querySelector('[data-notification-list]');
    const url = bell.dataset.unreadUrl;
    const readUrlTemplate = bell.dataset.readUrl;

    function render(data) {
        if (badge) {
            badge.textContent = data.count > 99 ? '99+' : String(data.count);
            badge.hidden = data.count === 0;
        }

        if (!list) return;

        if (!data.items.length) {
            list.innerHTML =
                '<p class="notification-text" style="padding: var(--space-5); text-align:center;">'
                + 'Nothing new right now.</p>';
            return;
        }

        list.innerHTML = data.items
            .map((item) => {
                // Server-provided strings are escaped here rather than
                // interpolated raw: a venue or customer name can contain
                // anything a person can type.
                const escape = (value) =>
                    String(value ?? '').replace(
                        /[&<>"']/g,
                        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
                    );

                const href = readUrlTemplate.replace('__ID__', encodeURIComponent(item.id));

                return `<a href="${href}" class="notification is-unread">
                    <span class="notification-icon tone-${escape(item.tone)}">${icon(item.icon)}</span>
                    <span class="notification-body">
                        <span class="notification-title">${escape(item.title)}</span>
                        <span class="notification-text">${escape(item.body)}</span>
                        <span class="notification-time">${escape(item.ago)}</span>
                    </span>
                </a>`;
            })
            .join('');
    }

    async function poll() {
        // Skip while the tab is hidden -- a backgrounded tab polling all day is
        // pure waste on shared hosting.
        if (document.hidden) return;

        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (response.ok) render(await response.json());
        } catch {
            // A failed poll is not worth surfacing; the next one will retry.
        }
    }

    poll();
    setInterval(poll, POLL_INTERVAL);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll();
    });
}
