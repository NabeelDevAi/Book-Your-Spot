<x-ui.card
    title="Delete account"
    subtitle="This permanently removes your account and cannot be undone."
>
    <p class="text-secondary" style="margin-bottom: var(--space-4);">
        Deleting your account removes your profile and booking history. If you have
        upcoming confirmed bookings, cancel them first so the venue isn't left
        holding a spot for you.
    </p>

    <x-ui.button variant="danger-outline" type="button" icon="trash" data-modal-open="delete-account">
        Delete account
    </x-ui.button>

    <x-ui.modal
        id="delete-account"
        title="Delete your account?"
        subtitle="This is permanent. Enter your password to confirm."
        width="narrow"
    >
        <form method="POST" action="{{ route('profile.destroy') }}" class="form" id="delete-account-form">
            @csrf
            @method('delete')

            <x-ui.field label="Password" name="password" bag="userDeletion" required>
                <x-ui.input name="password" type="password" bag="userDeletion" required placeholder="••••••••" />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" type="button" data-modal-close>Keep my account</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="delete-account-form">Delete permanently</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- If validation failed, Laravel redirects back -- reopen the dialog so the
         error is visible rather than hidden inside a closed modal. --}}
    @if ($errors->userDeletion->isNotEmpty())
        @push('scripts')
            <script>
                document.getElementById('delete-account')?.showModal();
            </script>
        @endpush
    @endif
</x-ui.card>
