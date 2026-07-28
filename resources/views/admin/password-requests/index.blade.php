<x-console-layout title="Password requests" context="admin">
    <x-ui.page-header
        title="Password requests"
        description="Users who can't log in. Verify them by phone before issuing a temporary password."
    />

    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        V1 doesn't send reset emails. Call the number on the account to confirm identity,
        then set a temporary password here and read it to them — they'll be forced to
        change it at next login.
    </x-ui.alert>

    <x-ui.card flush>
        @if ($requests->isEmpty())
            <x-ui.empty-state icon="lock" title="No password requests">
                When someone uses the "forgotten password" form, their request appears here.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Account</th>
                            <th>Submitted email</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $resetRequest)
                            <tr class="{{ $resetRequest->isOpen() ? 'is-attention' : 'is-muted' }}">
                                <td>
                                    <div class="cell-primary">{{ $resetRequest->user->name }}</div>
                                    <div class="cell-secondary">
                                        {{ $resetRequest->user->phone ?? 'No phone on file' }}
                                        · {{ $resetRequest->user->role->label() }}
                                    </div>
                                </td>
                                <td>
                                    {{ $resetRequest->submitted_email }}
                                    @if ($resetRequest->submitted_email !== $resetRequest->user->email)
                                        <div class="cell-secondary">
                                            Account email: {{ $resetRequest->user->email }}
                                        </div>
                                    @endif
                                </td>
                                <td class="cell-secondary">
                                    {{ $resetRequest->created_at->format('j M Y, g:i A') }}
                                </td>
                                <td>
                                    <x-ui.badge :variant="$resetRequest->status->badge()">
                                        {{ $resetRequest->status->label() }}
                                    </x-ui.badge>
                                    @if ($resetRequest->resolver)
                                        <div class="cell-secondary">by {{ $resetRequest->resolver->name }}</div>
                                    @endif
                                </td>
                                <td class="cell-actions">
                                    @if ($resetRequest->isOpen())
                                        <div class="btn-group">
                                            <x-ui.button
                                                variant="primary"
                                                size="sm"
                                                type="button"
                                                data-modal-open="issue-{{ $resetRequest->id }}"
                                            >Issue password</x-ui.button>

                                            <form method="POST"
                                                  action="{{ route('admin.password-requests.dismiss', $resetRequest) }}"
                                                  data-confirm="Dismiss this request?"
                                                  data-confirm-detail="No password will be changed."
                                                  data-confirm-action="Dismiss">
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm">Dismiss</x-ui.button>
                                            </form>
                                        </div>
                                    @else
                                        <span class="text-muted text-sm">
                                            {{ $resetRequest->resolved_at?->format('j M Y') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">
                {{ $requests->links() }}
            </div>
        @endif
    </x-ui.card>

    @foreach ($requests->where('status', \App\Enums\PasswordResetStatus::Open) as $resetRequest)
        <x-ui.modal
            id="issue-{{ $resetRequest->id }}"
            title="Issue a temporary password"
            subtitle="For {{ $resetRequest->user->name }} ({{ $resetRequest->user->email }})"
        >
            <form method="POST"
                  action="{{ route('admin.password-requests.issue', $resetRequest) }}"
                  class="form"
                  id="issue-form-{{ $resetRequest->id }}">
                @csrf

                <x-ui.alert variant="warning">
                    Confirm their identity on {{ $resetRequest->user->phone ?? 'their registered number' }}
                    before setting this.
                </x-ui.alert>

                <x-ui.field
                    label="Temporary password"
                    name="temporary_password"
                    required
                    hint="Read this to them directly. They must change it at next login."
                >
                    <x-ui.input name="temporary_password" required minlength="8" autocomplete="off" />
                </x-ui.field>

                <x-ui.field label="Note" name="note" hint="Optional — recorded in the audit log.">
                    <x-ui.textarea name="note" rows="2" placeholder="e.g. Verified by phone at 3:15 PM" />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" data-modal-close>Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" form="issue-form-{{ $resetRequest->id }}">
                    Set temporary password
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach
</x-console-layout>
