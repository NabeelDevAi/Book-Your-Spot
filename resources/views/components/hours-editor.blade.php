@props([
    'hours',                    // App\Support\OperatingHours
    'name' => 'operating_hours',
])

@php
    use App\Support\OperatingHours;
@endphp

{{--
    Weekly hours editor. Input names match OperatingHours::fromFormInput():
      operating_hours[mon][closed]
      operating_hours[mon][ranges][0][open|close]

    A day with no ranges is closed. A close time at or before its open time is
    an overnight range (16:00–02:00), which is normal here and is confirmed to
    the owner rather than rejected.
--}}

<div class="hours-editor" data-hours-editor>
    @foreach (OperatingHours::DAYS as $day)
        @php
            // old() wins on a validation bounce so the owner doesn't lose their work.
            $oldDay = old($name.'.'.$day);
            $isClosed = $oldDay !== null
                ? ! empty($oldDay['closed'])
                : $hours->isClosedOn($day);

            $ranges = $oldDay !== null
                ? array_values($oldDay['ranges'] ?? [])
                : $hours->forDay($day);

            if ($ranges === []) {
                $ranges = [['open' => '14:00', 'close' => '23:00']];
            }
        @endphp

        <div class="hours-day" data-hours-day="{{ $day }}">
            <div class="hours-day-label">
                <span class="hours-day-name">{{ OperatingHours::DAY_LABELS[$day] }}</span>

                <label class="checkbox text-sm">
                    <input
                        type="checkbox"
                        name="{{ $name }}[{{ $day }}][closed]"
                        value="1"
                        data-hours-closed
                        @checked($isClosed)
                    >
                    <span class="text-muted">Closed</span>
                </label>
            </div>

            <div>
                <div class="hours-ranges" data-hours-ranges>
                    @foreach ($ranges as $index => $range)
                        <div class="hours-range" data-hours-range>
                            <input
                                type="time"
                                class="input"
                                data-bound="open"
                                name="{{ $name }}[{{ $day }}][ranges][{{ $index }}][open]"
                                value="{{ $range['open'] ?? '' }}"
                                aria-label="{{ OperatingHours::DAY_LABELS[$day] }} opening time"
                            >

                            <span class="hours-range-sep">to</span>

                            <input
                                type="time"
                                class="input"
                                data-bound="close"
                                name="{{ $name }}[{{ $day }}][ranges][{{ $index }}][close]"
                                value="{{ $range['close'] ?? '' }}"
                                aria-label="{{ OperatingHours::DAY_LABELS[$day] }} closing time"
                            >

                            <span class="hours-overnight">
                                <x-ui.icon name="clock" :size="11" /> next day
                            </span>

                            <button type="button" class="hours-range-remove" data-hours-remove
                                    aria-label="Remove this time range">
                                <x-ui.icon name="x" :size="14" />
                            </button>
                        </div>
                    @endforeach
                </div>

                <div class="hours-closed-note">Closed all day.</div>

                <div class="cluster-2" style="margin-top: var(--space-2);">
                    <button type="button" class="btn btn-ghost btn-sm" data-hours-add>
                        <x-ui.icon name="plus" :size="13" /> Add split shift
                    </button>

                    <button type="button" class="btn btn-ghost btn-sm" data-hours-copy>
                        <x-ui.icon name="refresh" :size="13" /> Copy to all days
                    </button>
                </div>
            </div>
        </div>
    @endforeach
</div>

@error($name)
    <p class="field-error" style="margin-top: var(--space-2);">{{ $message }}</p>
@enderror

{{-- Per-day messages from ValidOperatingHours arrive keyed to the parent
     attribute, so surface every one rather than only the first. --}}
@php
    $hoursErrors = $errors->get($name);
@endphp
@if (count($hoursErrors) > 1)
    <ul class="field-error" style="margin-top: var(--space-1); padding-left: var(--space-4); list-style: disc;">
        @foreach (array_slice($hoursErrors, 1) as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
