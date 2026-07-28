<x-console-layout title="Audit log" context="admin">
    <x-ui.page-header
        title="Audit log"
        description="Every moderation action and system decision (NFR-6, SRS 9.20)."
    />

    <x-ui.alert variant="neutral" style="margin-bottom: var(--space-5);">
        {{-- There is deliberately no edit or delete route for these rows. --}}
        This log is append-only. Entries can't be edited or removed — a log that can be altered
        is worth nothing in the disputes it exists to settle.
    </x-ui.alert>

    <form method="GET" class="filter-bar">
        <x-ui.field label="Action" name="action" style="flex: 1 1 auto;">
            <x-ui.select name="action" placeholder="All actions">
                @foreach ($actions as $action)
                    <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Source">
            <label class="checkbox" style="height: 34px;">
                <input type="checkbox" name="system_only" value="1" @checked(request()->boolean('system_only'))>
                <span>System actions only</span>
            </label>
        </x-ui.field>

        <div class="filter-bar-actions">
            <x-ui.button type="submit" variant="secondary" icon="filter">Filter</x-ui.button>
            @if (request()->hasAny(['action', 'system_only']))
                <x-ui.button :href="route('admin.audit.index')" variant="ghost">Clear</x-ui.button>
            @endif
        </div>
    </form>

    <x-ui.card flush>
        @if ($logs->isEmpty())
            <x-ui.empty-state icon="shield" title="Nothing logged yet">
                Moderation actions and scheduled decisions appear here as they happen.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table table-compact">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Action</th>
                            <th>Actor</th>
                            <th>Target</th>
                            <th>Reason / detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($logs as $log)
                            <tr class="{{ $log->isSystemAction() ? 'is-muted' : '' }}">
                                <td class="cell-secondary cell-tight">{{ $log->created_at->format('j M Y, g:i A') }}</td>
                                <td class="mono cell-tight">{{ $log->action }}</td>
                                <td>
                                    {{ $log->actorLabel() }}
                                    @if ($log->actor_role)
                                        <div class="cell-secondary">{{ $log->actor_role->label() }}</div>
                                    @endif
                                </td>
                                <td class="cell-secondary">
                                    {{ class_basename($log->target_type ?? '—') }}
                                    @if ($log->target_id)#{{ $log->target_id }}@endif
                                </td>
                                <td>
                                    {{ $log->reason }}
                                    @if ($log->meta)
                                        <div class="cell-secondary mono">
                                            {{ Str::limit(json_encode($log->meta), 120) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">{{ $logs->links() }}</div>
        @endif
    </x-ui.card>
</x-console-layout>
