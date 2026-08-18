@php
    $isEdit = isset($spot) && $spot !== null;
    $units = config('booking.allowed_price_unit_minutes');
    $overrideChecked = old('override_hours', $isEdit ? $spot->hasHoursOverride() : false);
@endphp

<div class="stack-6">
    <x-ui.card title="Spot details">
        <div class="form">
            <div class="form-row">
                <x-ui.field
                    label="Spot name"
                    name="name"
                    required
                    hint="What customers will see — “Table 1”, “Court 2”, “Room A”."
                >
                    <x-ui.input
                        name="name"
                        :value="$isEdit ? $spot->name : null"
                        required
                        autofocus
                        placeholder="Snooker Table 1"
                    />
                </x-ui.field>

                <x-ui.field label="Category" name="business_game_id" required>
                    @if ($isEdit)
                        {{-- Moving a spot between categories would orphan its
                             booking history under the wrong heading. --}}
                        <x-ui.input :value="$spot->businessGame->game->name" disabled />
                        <p class="field-hint">A spot's category can't be changed after it's created.</p>
                    @else
                        <x-ui.select name="business_game_id" required :selected="$selectedGameId">
                            @foreach ($businessGames as $businessGame)
                                <option value="{{ $businessGame->id }}"
                                    @selected((int) old('business_game_id', $selectedGameId) === $businessGame->id)>
                                    {{ $businessGame->game->name }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    @endif
                </x-ui.field>
            </div>

            <x-ui.field label="Description" name="description">
                <x-ui.textarea
                    name="description"
                    :value="$isEdit ? $spot->description : null"
                    rows="2"
                    placeholder="Tournament-size table, recently re-clothed."
                />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card
        title="Pricing"
        subtitle="Changing this later affects new bookings only — confirmed bookings keep the price they were made at."
    >
        <div class="form">
            <div class="form-row">
                <x-ui.field label="Weekday rate" name="price_amount" required hint="Mon–Fri.">
                    <x-ui.input
                        name="price_amount"
                        type="number"
                        step="1"
                        min="1"
                        :value="$isEdit ? (int) $spot->price_amount : null"
                        required
                        prefix="Rs."
                        placeholder="100"
                    />
                </x-ui.field>

                <x-ui.field
                    label="Weekend rate"
                    name="weekend_price_amount"
                    hint="Sat, Sun and public holidays (and the day before one). Leave blank to charge the weekday rate."
                >
                    <x-ui.input
                        name="weekend_price_amount"
                        type="number"
                        step="1"
                        min="1"
                        :value="$isEdit ? ($spot->weekend_price_amount !== null ? (int) $spot->weekend_price_amount : null) : null"
                        prefix="Rs."
                        placeholder="Same as weekday"
                    />
                </x-ui.field>
            </div>

            <x-ui.field
                label="Per"
                name="price_unit_minutes"
                required
                hint="Bookings are billed and validated in whole units of this."
            >
                <x-ui.select
                    name="price_unit_minutes"
                    required
                    :selected="$isEdit ? $spot->price_unit_minutes : 60"
                >
                    @foreach ($units as $unit)
                        <option value="{{ $unit }}"
                            @selected((int) old('price_unit_minutes', $isEdit ? $spot->price_unit_minutes : 60) === $unit)>
                            {{ \App\Support\Money::duration($unit) }}
                        </option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            {{-- SRS 9.7: must be a whole billing unit, or the spot advertises
                 a minimum it can't price. Enforced in SpotRequest. There is no
                 maximum any more -- a booking can run as long as a single day's
                 opening hours allow. --}}
            <x-ui.field
                label="Minimum booking"
                name="min_duration_minutes"
                required
                hint="Must be a multiple of the billing unit. No maximum — a booking can run until the spot closes for the day."
            >
                <x-ui.input
                    name="min_duration_minutes"
                    type="number"
                    step="1"
                    min="1"
                    :value="$isEdit ? $spot->min_duration_minutes : 60"
                    required
                    affix="min"
                />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card title="Opening hours">
        <label class="checkbox" style="margin-bottom: var(--space-4);">
            <input type="hidden" name="override_hours" value="0">
            <input type="checkbox" name="override_hours" value="1" @checked($overrideChecked)>
            <span>This spot has different hours from the venue</span>
        </label>

        <p class="field-hint" style="margin-bottom: var(--space-4);">
            Leave unticked and it follows {{ $business->name }}'s hours:
            <strong>{{ $business->hours()->summary() }}</strong>
        </p>

        <x-hours-editor :hours="$hours" />
    </x-ui.card>

    <x-ui.card
        title="Photos"
        subtitle="Up to {{ config('booking.max_spot_images') }} photos of this specific spot."
    >
        <x-image-uploader
            :images="$isEdit ? $spot->images->where('media_type', 'image') : null"
            :max="config('booking.max_spot_images')"
            :delete-route="$isEdit
                ? fn ($image) => route('owner.businesses.spots.images.destroy', [$business, $spot, $image])
                : null"
        />
    </x-ui.card>

    <x-ui.card
        title="Videos"
        subtitle="Up to {{ config('booking.max_spot_videos') }} short clips of this spot."
    >
        <x-video-uploader
            :videos="$isEdit ? $spot->images->where('media_type', 'video') : null"
            :max="config('booking.max_spot_videos')"
            :delete-route="$isEdit
                ? fn ($video) => route('owner.businesses.spots.images.destroy', [$business, $spot, $video])
                : null"
        />
    </x-ui.card>
</div>
