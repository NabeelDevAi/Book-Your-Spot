<x-console-layout title="Blocked times" context="owner">
    <x-ui.page-header
        title="Blocked times"
        description="Take {{ $spot->name }} off the calendar for maintenance, a private event, or your own use."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.spots.index', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.spots.edit', [$business, $spot]) }}" class="link-muted">{{ $spot->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>Blocked times</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    {{-- SRS 9.14: owners can't book their own spots as customers, so this is
         the supported way to reserve your own table. --}}
    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        Blocking is how you keep a spot for yourself or take it out of service for a while.
        It won't appear as a customer booking or affect your statistics.
    </x-ui.alert>

    @if (session('pending_block_conflicts'))
        {{-- SRS 9.6: the owner is warned BEFORE the block is created. Finding out
             afterwards means the block is already sitting on a booking they may
             have wanted to keep. --}}
        <x-ui.alert variant="warning" title="Existing bookings fall inside that window" style="margin-bottom: var(--space-5);">
            <ul>
                @foreach (session('pending_block_conflicts') as $conflict)
                    <li>
                        <strong>{{ $conflict['reference'] }}</strong> — {{ $conflict['customer'] }},
                        {{ $conflict['when'] }}
                    </li>
                @endforeach
            </ul>
            <p style="margin-top: var(--space-2);">
                Confirm below to add the block anyway. These bookings will <strong>not</strong> be
                cancelled — each is flagged for you to contact the customer and agree what happens.
            </p>
        </x-ui.alert>
    @endif

    <div class="layout-with-aside">
        <x-ui.card title="Scheduled blocks" flush>
            @if ($blocks->isEmpty())
                <x-ui.empty-state icon="calendar" title="No blocked times">
                    This spot is bookable during all of its opening hours.
                </x-ui.empty-state>
            @else
                <div class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>From</th>
                                <th>To</th>
                                <th>Reason</th>
                                <th>Added by</th>
                                <th class="cell-actions"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($blocks as $block)
                                <tr class="{{ $block->end_datetime->isPast() ? 'is-muted' : '' }}">
                                    <td>
                                        <div class="cell-primary">{{ $block->start_datetime->format('D, j M Y') }}</div>
                                        <div class="cell-secondary">{{ $block->start_datetime->format('g:i A') }}</div>
                                    </td>
                                    <td>
                                        <div class="cell-primary">{{ $block->end_datetime->format('D, j M Y') }}</div>
                                        <div class="cell-secondary">{{ $block->end_datetime->format('g:i A') }}</div>
                                    </td>
                                    <td>{{ $block->reason ?? '—' }}</td>
                                    <td class="cell-secondary">{{ $block->creator?->name ?? '—' }}</td>
                                    <td class="cell-actions">
                                        @if ($block->end_datetime->isFuture())
                                            <form method="POST"
                                                  action="{{ route('owner.businesses.spots.blocks.destroy', [$business, $spot, $block]) }}"
                                                  data-confirm="Remove this block?"
                                                  data-confirm-detail="That time becomes bookable again."
                                                  data-confirm-action="Remove block">
                                                @csrf
                                                @method('delete')
                                                <x-ui.button type="submit" variant="ghost" size="sm" icon="trash">
                                                    Remove
                                                </x-ui.button>
                                            </form>
                                        @else
                                            <span class="text-muted text-sm">Past</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div style="padding: 0 var(--space-5) var(--space-4);">{{ $blocks->links() }}</div>
            @endif
        </x-ui.card>

        <x-ui.card title="Add a block" class="aside-sticky">
            <form method="POST"
                  action="{{ route('owner.businesses.spots.blocks.store', [$business, $spot]) }}"
                  class="form">
                @csrf

                <x-ui.field label="From" name="start_datetime" required>
                    <x-ui.input
                        name="start_datetime"
                        type="datetime-local"
                        :value="old('start_datetime')"
                        required
                    />
                </x-ui.field>

                <x-ui.field label="To" name="end_datetime" required>
                    <x-ui.input
                        name="end_datetime"
                        type="datetime-local"
                        :value="old('end_datetime')"
                        required
                    />
                </x-ui.field>

                <x-ui.field label="Reason" name="reason" hint="Optional — only you and our team see this.">
                    <x-ui.input name="reason" :value="old('reason')" placeholder="Table re-clothing" />
                </x-ui.field>

                @if (session('pending_block_conflicts'))
                    <label class="checkbox">
                        <input type="checkbox" name="acknowledge_conflicts" value="1" required>
                        <span>I understand existing bookings will be flagged for me to resolve</span>
                    </label>
                @endif

                <x-ui.button type="submit" variant="primary" block>
                    {{ session('pending_block_conflicts') ? 'Add block anyway' : 'Add block' }}
                </x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-console-layout>
