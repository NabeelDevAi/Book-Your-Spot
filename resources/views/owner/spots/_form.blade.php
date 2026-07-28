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
                <x-ui.field label="Rate" name="price_amount" required>
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
            </div>

            {{-- SRS 9.7: both bounds must be whole billing units, or the spot
                 advertises durations it can't price. Enforced in SpotRequest. --}}
            <div class="form-row">
                <x-ui.field
                    label="Minimum booking"
                    name="min_duration_minutes"
                    required
                    hint="Must be a multiple of the billing unit."
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

                <x-ui.field label="Maximum booking" name="max_duration_minutes" required>
                    <x-ui.input
                        name="max_duration_minutes"
                        type="number"
                        step="1"
                        min="1"
                        :value="$isEdit ? $spot->max_duration_minutes : 180"
                        required
                        affix="min"
                    />
                </x-ui.field>
            </div>
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
            :images="$isEdit ? $spot->images : null"
            :max="config('booking.max_spot_images')"
            :delete-route="$isEdit
                ? fn ($image) => route('owner.businesses.spots.images.destroy', [$business, $spot, $image])
                : null"
        />
    </x-ui.card>
</div>
