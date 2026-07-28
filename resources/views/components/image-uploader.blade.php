@props([
    'images' => null,       // existing image models (nullable on create)
    'max' => 5,
    'deleteRoute' => null,  // closure: fn($image) => url
    'name' => 'images',
])

@php
    $existing = $images ? $images->count() : 0;
    $remaining = max(0, $max - $existing);
    $maxKb = (int) config('booking.max_image_kilobytes');
@endphp

<div class="upload" data-upload data-upload-remaining="{{ $remaining }}" data-upload-max-kb="{{ $maxKb }}">
    @if ($images && $images->isNotEmpty())
        <div class="image-gallery">
            @foreach ($images as $image)
                <div class="image-tile">
                    <img src="{{ $image->url() }}" alt="{{ $image->caption ?? '' }}">

                    @if ($loop->first)
                        <span class="image-tile-primary">Cover</span>
                    @endif

                    @if ($deleteRoute)
                        <form method="POST" action="{{ $deleteRoute($image) }}"
                              data-confirm="Remove this photo?"
                              data-confirm-detail="It will be deleted permanently."
                              data-confirm-action="Remove photo">
                            @csrf
                            @method('delete')
                            <button type="submit" class="image-tile-remove" aria-label="Remove photo">
                                <x-ui.icon name="x" :size="14" />
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if ($remaining > 0)
        <label class="upload-dropzone" data-upload-trigger>
            <span class="upload-dropzone-icon"><x-ui.icon name="image" :size="24" /></span>
            <span class="upload-dropzone-title">Add photos</span>
            <span class="upload-dropzone-hint">
                JPG, PNG or WebP · up to {{ round($maxKb / 1024) }} MB each ·
                {{ $remaining }} {{ Str::plural('slot', $remaining) }} left
            </span>

            <input
                type="file"
                class="upload-input"
                name="{{ $name }}[]"
                accept="image/jpeg,image/png,image/webp"
                multiple
                data-upload-input
            >
        </label>

        <div class="upload-previews" data-upload-previews></div>
        <p class="upload-counter" data-upload-counter></p>
    @else
        <p class="field-hint">
            You've reached the {{ $max }} photo limit. Remove one to add another.
        </p>
    @endif

    @error($name)<p class="field-error">{{ $message }}</p>@enderror
    @error($name.'.*')<p class="field-error">{{ $message }}</p>@enderror
</div>
