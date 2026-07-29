{{--
    Phase 0 placeholder. Role-specific dashboards (User bookings, Owner console,
    Admin console) are built in their own phases.
--}}
<x-app-layout title="Dashboard">
    <x-ui.page-header
        title="Welcome back, {{ auth()->user()->name }}"
        description="Your bookings and account activity will appear here."
    />

    <div class="stat-row" data-reveal-group>
        <x-ui.stat label="Upcoming bookings" value="0" meta="Nothing scheduled yet" />
        <x-ui.stat label="Awaiting confirmation" value="0" meta="No pending requests" tone="attention" />
        <x-ui.stat label="Completed" value="0" meta="All time" />
        <x-ui.stat label="Venues browsed" value="0" meta="Start exploring" />
    </div>

    <x-ui.card title="Recent activity">
        <x-ui.empty-state icon="calendar" title="No bookings yet">
            Once you request a spot, you'll see it here along with its status.

            <x-slot:action>
                <x-ui.button :href="route('home')" variant="primary" icon="search">Browse venues</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    </x-ui.card>
</x-app-layout>
