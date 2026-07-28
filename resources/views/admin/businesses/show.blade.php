<x-console-layout :title="$business->name" context="admin">
    <x-ui.page-header :title="$business->name">
        <x-slot:breadcrumb>
            <a href="{{ route('admin.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $business->name }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-ui.badge :variant="$business->status->badge()">{{ $business->status->label() }}</x-ui.badge>
            <x-ui.button :href="route('admin.businesses.edit', $business)" variant="secondary" icon="edit">
                Override edit
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($business->duplicate_flagged)
        {{-- SRS 9.11: flagged, not blocked. Two venues in adjacent units may
             genuinely share a landline, so this is a prompt to look, not a verdict. --}}
        <x-ui.alert variant="warning" title="Flagged as a possible duplicate" style="margin-bottom: var(--space-5);">
            {{ $business->duplicate_note }}

            @if ($possibleDuplicates->isNotEmpty())
                <ul>
                    @foreach ($possibleDuplicates as $other)
                        <li>
                            <a href="{{ route('admin.businesses.show', $other) }}" class="link">{{ $other->name }}</a>
                            — {{ $other->address }}, {{ $other->area }} · {{ $other->contact_number }}
                        </li>
                    @endforeach
                </ul>
            @endif

            <form method="POST" action="{{ route('admin.businesses.clear-duplicate', $business) }}" style="margin-top: var(--space-3);">
                @csrf
                <x-ui.button type="submit" variant="secondary" size="sm">This is genuine — clear the flag</x-ui.button>
            </form>
        </x-ui.alert>
    @endif

    @if ($business->status === \App\Enums\BusinessStatus::Rejected)
        <x-ui.alert variant="danger" title="Rejected" style="margin-bottom: var(--space-5);">
            {{ $business->rejection_reason }}
            <br><span class="text-sm">The owner can edit and save the venue to resubmit it.</span>
        </x-ui.alert>
    @elseif ($business->isSuspended())
        <x-ui.alert variant="danger" title="Suspended" style="margin-bottom: var(--space-5);">
            {{ $business->suspension_reason }}
        </x-ui.alert>
    @endif

    <div class="layout-with-aside">
        <div class="stack-6">
            <x-ui.card title="Venue details">
                <dl class="detail-list">
                    <dt>Owner</dt>
                    <dd>
                        <a href="{{ route('admin.users.show', $business->owner) }}" class="link">
                            {{ $business->owner->name }}
                        </a>
                        <div class="text-sm text-muted">
                            {{ $business->owner->email }} · {{ $business->owner->phone }}
                        </div>
                    </dd>
                    <dt>Address</dt><dd>{{ $business->address }}, {{ $business->area }}, {{ $business->city }}</dd>
                    <dt>Contact</dt><dd>{{ $business->contact_number }}</dd>
                    <dt>Hours</dt><dd>{{ $business->hours()->summary() }}</dd>
                    <dt>Submitted</dt><dd>{{ $business->created_at->format('j M Y, g:i A') }}</dd>
                    @if ($business->reviewed_at)
                        <dt>Last reviewed</dt>
                        <dd>{{ $business->reviewed_at->format('j M Y, g:i A') }}</dd>
                    @endif
                    @if ($business->description)
                        <dt>Description</dt><dd>{{ $business->description }}</dd>
                    @endif
                </dl>
            </x-ui.card>

            <x-ui.card title="What they offer" flush>
                @if ($business->spots->isEmpty())
                    <x-ui.empty-state icon="grid" title="No spots listed">
                        Nothing is bookable here, so this venue stays hidden from search even once approved.
                    </x-ui.empty-state>
                @else
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr><th>Spot</th><th>Category</th><th>Rate</th><th>Length</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($business->spots as $spot)
                                    <tr>
                                        <td class="cell-primary">{{ $spot->name }}</td>
                                        <td>{{ $business->businessGames->firstWhere('id', $spot->business_game_id)?->game->name }}</td>
                                        <td class="mono">{{ $spot->rateLabel() }}</td>
                                        <td class="cell-secondary">
                                            {{ \App\Support\Money::duration($spot->min_duration_minutes) }}
                                            – {{ \App\Support\Money::duration($spot->max_duration_minutes) }}
                                        </td>
                                        <td>
                                            <x-ui.badge :variant="$spot->status->badge()">
                                                {{ $spot->status->label() }}
                                            </x-ui.badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-ui.card>

            {{-- NFR-6 / SRS 9.20: the trail that settles "who changed what". --}}
            <x-ui.card title="Moderation history">
                @if ($auditTrail->isEmpty())
                    <p class="text-muted text-sm">No moderation actions recorded yet.</p>
                @else
                    <div class="stack-3">
                        @foreach ($auditTrail as $log)
                            <div class="split split-start" style="padding-bottom: var(--space-2); border-bottom: 1px solid var(--border-color);">
                                <div>
                                    <div class="font-medium">{{ str_replace(['business.', '_'], ['', ' '], $log->action) }}</div>
                                    @if ($log->reason)
                                        <div class="text-sm text-muted">{{ $log->reason }}</div>
                                    @endif
                                </div>
                                <div class="text-right text-xs text-muted">
                                    {{ $log->actorLabel() }}<br>
                                    {{ $log->created_at->format('j M Y, g:i A') }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </div>

        <aside class="aside-sticky">
            <x-ui.card title="Moderation">
                <div class="stack-3">
                    @if ($business->isPendingReview() || $business->status === \App\Enums\BusinessStatus::Rejected)
                        <form method="POST" action="{{ route('admin.businesses.approve', $business) }}">
                            @csrf
                            <x-ui.button type="submit" variant="success" block icon="check">
                                Approve and publish
                            </x-ui.button>
                        </form>
                    @endif

                    @if ($business->isPendingReview())
                        <x-ui.button variant="danger-outline" block type="button" data-modal-open="reject-business">
                            Reject
                        </x-ui.button>
                    @endif

                    @if ($business->isActive())
                        <x-ui.button variant="danger-outline" block type="button" data-modal-open="suspend-business" icon="ban">
                            Suspend venue
                        </x-ui.button>

                        @if ($futureBookings > 0)
                            <p class="text-xs text-warning">
                                {{ $futureBookings }} upcoming {{ Str::plural('booking', $futureBookings) }}
                                would be cancelled and those customers notified.
                            </p>
                        @endif
                    @endif

                    @if ($business->isSuspended())
                        <form method="POST" action="{{ route('admin.businesses.reinstate', $business) }}"
                              data-confirm="Reinstate this venue?"
                              data-confirm-detail="It becomes bookable again. Bookings cancelled during the suspension are not restored."
                              data-confirm-action="Reinstate"
                              data-confirm-tone="info">
                            @csrf
                            <x-ui.button type="submit" variant="secondary" block icon="refresh">
                                Reinstate venue
                            </x-ui.button>
                        </form>
                    @endif
                </div>
            </x-ui.card>
        </aside>
    </div>

    <x-ui.modal id="reject-business" title="Reject this venue" subtitle="The owner is told why, and can fix it and resubmit.">
        <form method="POST" action="{{ route('admin.businesses.reject', $business) }}" class="form" id="reject-business-form">
            @csrf
            <x-ui.field label="Reason" name="reason" required hint="Sent to the owner verbatim — be specific enough to act on.">
                <x-ui.textarea name="reason" rows="3" required
                               placeholder="The address could not be verified during the site visit." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" type="button" data-modal-close>Back</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="reject-business-form">Reject venue</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- SRS 9.8: suspension is not just a status change -- it cascades to every
         future booking, and the dialog says so before the click. --}}
    <x-ui.modal id="suspend-business" title="Suspend this venue" subtitle="It disappears from search immediately.">
        <form method="POST" action="{{ route('admin.businesses.suspend', $business) }}" class="form" id="suspend-business-form">
            @csrf

            @if ($futureBookings > 0)
                <x-ui.alert variant="danger" title="{{ $futureBookings }} upcoming {{ Str::plural('booking', $futureBookings) }} will be cancelled">
                    Those customers will be told the venue is temporarily unavailable.
                    Bookings already in the past are left untouched.
                </x-ui.alert>
            @endif

            <x-ui.field label="Reason" name="reason" required hint="Recorded in the audit log and sent to the owner.">
                <x-ui.textarea name="reason" rows="3" required
                               placeholder="Multiple unresolved customer complaints." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" type="button" data-modal-close>Cancel</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="suspend-business-form">Suspend venue</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-console-layout>
