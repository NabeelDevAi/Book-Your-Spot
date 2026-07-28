{{--
    Renders session flash messages and the global validation summary.
    Included once by each layout, so controllers just do
    ->with('success', '...') and it appears.
--}}

<div class="flash-region">
    @if (session('success'))
        <x-ui.alert variant="success" dismissible :auto-dismiss="6000">
            {{ session('success') }}
        </x-ui.alert>
    @endif

    @if (session('error'))
        <x-ui.alert variant="danger" dismissible>
            {{ session('error') }}
        </x-ui.alert>
    @endif

    @if (session('warning'))
        <x-ui.alert variant="warning" dismissible>
            {{ session('warning') }}
        </x-ui.alert>
    @endif

    @php
        // Laravel/Breeze put both human sentences ("We have emailed your reset
        // link") and internal machine keys ("profile-updated") into `status`.
        // The machine keys are rendered by the view that cares about them, so
        // skip them here rather than printing a slug at the user.
        $internalStatusKeys = ['profile-updated', 'password-updated', 'verification-link-sent'];
        $status = session('status');
        $status = in_array($status, $internalStatusKeys, true) ? null : $status;
    @endphp

    @if (session('info') || $status)
        <x-ui.alert variant="info" dismissible>
            {{ session('info') ?? $status }}
        </x-ui.alert>
    @endif

    {{-- Field-level errors render inline next to their input; this summary only
         appears for errors that have no field to attach to. --}}
    @if ($errors->has('_global'))
        <x-ui.alert variant="danger" title="We couldn't complete that">
            <ul>
                @foreach ($errors->get('_global') as $message)
                    <li>{{ $message }}</li>
                @endforeach
            </ul>
        </x-ui.alert>
    @endif
</div>
