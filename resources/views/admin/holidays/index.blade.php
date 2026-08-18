<x-console-layout title="Holidays" context="admin">
    <x-ui.page-header
        title="Holiday calendar"
        description="Every Spot bills its weekend rate on these dates, and on the day before each one."
    />

    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        One platform-wide calendar, not one per venue. A date listed here pushes every Spot with a
        weekend rate onto it for that day <strong>and the day before it</strong> — e.g. listing a
        Friday holiday also prices Thursday at the weekend rate.
    </x-ui.alert>

    <div class="layout-with-aside">
        <x-ui.card flush>
            @if ($holidays->isEmpty())
                <x-ui.empty-state icon="calendar" title="No holidays listed yet">
                    Add a date and every Spot's weekend rate applies to it automatically.
                </x-ui.empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Name</th>
                                <th class="cell-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($holidays as $holiday)
                                <tr>
                                    <td class="cell-primary">{{ $holiday->date->format('l, j M Y') }}</td>
                                    <td>{{ $holiday->name }}</td>
                                    <td class="cell-actions">
                                        <form method="POST" action="{{ route('admin.holidays.destroy', $holiday) }}"
                                              data-confirm="Remove &quot;{{ $holiday->name }}&quot;?"
                                              data-confirm-detail="Spots stop billing the weekend rate for this date and the day before it."
                                              data-confirm-action="Remove"
                                              data-confirm-tone="warning">
                                            @csrf
                                            @method('delete')
                                            <x-ui.button type="submit" variant="ghost" size="sm">Remove</x-ui.button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.card>

        <aside class="aside-sticky">
            <x-ui.card title="Add a holiday">
                <form method="POST" action="{{ route('admin.holidays.store') }}" class="form">
                    @csrf

                    <x-ui.field label="Date" name="date" required>
                        <x-ui.input name="date" type="date" required />
                    </x-ui.field>

                    <x-ui.field label="Name" name="name" required hint="Shown to owners and in the audit log.">
                        <x-ui.input name="name" required placeholder="Independence Day" />
                    </x-ui.field>

                    <x-ui.button type="submit" variant="primary" block icon="plus">Add holiday</x-ui.button>
                </form>
            </x-ui.card>
        </aside>
    </div>
</x-console-layout>
