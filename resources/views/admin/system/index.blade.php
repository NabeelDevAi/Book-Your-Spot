<x-console-layout title="System" context="admin">
    <x-ui.page-header
        title="System"
        description="Web equivalents of php artisan optimize / optimize:clear — for when there's no shell access on this deploy."
    />

    <div class="grid-2" data-reveal-group>
        <x-ui.card title="Optimize" subtitle="Cache config, routes, views and events.">
            <p class="text-sm text-muted" style="margin-bottom: var(--space-4);">
                Run this right after deploying new code. It's what makes config, routes and views fast in
                production — but it also means a config or route change won't take effect until you run
                this again (or Clear cache below).
            </p>

            <form method="POST" action="{{ route('admin.system.optimize') }}">
                @csrf
                <x-ui.button type="submit" variant="primary" icon="sparkle">Optimize</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Clear cache" subtitle="Clear config, route, view, event and application caches.">
            <p class="text-sm text-muted" style="margin-bottom: var(--space-4);">
                Run this when something looks stale — an env change isn't showing up, a route 404s that
                shouldn't, a view isn't reflecting a recent edit. Safe to run any time; nothing is deleted,
                only re-generated on the next request.
            </p>

            <form method="POST" action="{{ route('admin.system.clear-cache') }}">
                @csrf
                <x-ui.button type="submit" variant="secondary" icon="refresh">Clear cache</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-console-layout>
