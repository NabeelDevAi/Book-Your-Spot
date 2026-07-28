{{--
    Shared by create and edit. $business is null on create.
--}}
@php
    $isEdit = isset($business) && $business !== null;
@endphp

<div class="stack-6">
    <x-ui.card title="Venue details">
        <div class="form">
            <x-ui.field label="Venue name" name="name" required>
                <x-ui.input
                    name="name"
                    :value="$isEdit ? $business->name : null"
                    required
                    autofocus
                    placeholder="e.g. Cue &amp; Console Gaming Zone"
                />
            </x-ui.field>

            <x-ui.field
                label="Description"
                name="description"
                hint="What makes your venue worth the trip — facilities, parking, cafe."
            >
                <x-ui.textarea
                    name="description"
                    :value="$isEdit ? $business->description : null"
                    rows="4"
                    placeholder="Eight snooker tables and four PS5 rooms, air-conditioned, cafe on site."
                />
            </x-ui.field>

            <x-ui.field label="Street address" name="address" required>
                <x-ui.input
                    name="address"
                    :value="$isEdit ? $business->address : null"
                    required
                    placeholder="12-C, Khayaban-e-Bukhari"
                />
            </x-ui.field>

            <div class="form-row">
                <x-ui.field
                    label="Area / locality"
                    name="area"
                    required
                    hint="Customers filter by this, so use the name locals would search for."
                >
                    <x-ui.input
                        name="area"
                        :value="$isEdit ? $business->area : null"
                        required
                        list="known-areas"
                        placeholder="DHA Phase 6"
                    />
                    <datalist id="known-areas">
                        @foreach ($areas as $area)
                            <option value="{{ $area }}"></option>
                        @endforeach
                    </datalist>
                </x-ui.field>

                <x-ui.field label="City" name="city" required>
                    {{-- V1 is single-city; the field exists for future expansion
                         and is not exposed as a customer filter yet (SRS 2.4). --}}
                    <x-ui.input name="city" :value="$isEdit ? $business->city : 'Karachi'" required />
                </x-ui.field>
            </div>

            <x-ui.field
                label="Contact number"
                name="contact_number"
                required
                hint="Customers and our team use this to reach you about bookings."
            >
                <x-ui.input
                    name="contact_number"
                    type="tel"
                    :value="$isEdit ? $business->contact_number : null"
                    required
                    placeholder="+92 21 3584 0001"
                />
            </x-ui.field>
        </div>
    </x-ui.card>

    <x-ui.card
        title="Opening hours"
        subtitle="Individual spots can override these if they close earlier."
    >
        <x-hours-editor :hours="$hours" />
    </x-ui.card>

    <x-ui.card
        title="Photos"
        subtitle="Up to {{ config('booking.max_business_images') }}. The first is used as the cover."
    >
        <x-image-uploader
            :images="$isEdit ? $business->images : null"
            :max="config('booking.max_business_images')"
            :delete-route="$isEdit
                ? fn ($image) => route('owner.businesses.images.destroy', [$business, $image])
                : null"
        />
    </x-ui.card>
</div>
