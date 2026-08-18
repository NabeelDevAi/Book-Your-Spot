@props([
    'videos' => null,       // existing video models (nullable on create)
    'max' => 2,
    'deleteRoute' => null,  // closure: fn($video) => url
    'name' => 'videos',
])

@php
    $existing = $videos ? $videos->count() : 0;
    $remaining = max(0, $max - $existing);
    $maxKb = (int) config('booking.max_video_kilobytes');
@endphp

<div class="upload" data-upload data-upload-remaining="{{ $remaining }}" data-upload-max-kb="{{ $maxKb }}" data-upload-kind="video">
    @if ($videos && $videos->isNotEmpty())
        <div class="image-gallery">
            @foreach ($videos as $video)
                <div class="image-tile">
                    <video src="{{ $video->url() }}" muted playsinline preload="metadata"></video>

                    @if ($deleteRoute)
                        <form method="POST" action="{{ $deleteRoute($video) }}"
                              data-confirm="Remove this video?"
                              data-confirm-detail="It will be deleted permanently."
                              data-confirm-action="Remove video">
                            @csrf
                            @method('delete')
                            <button type="submit" class="image-tile-remove" aria-label="Remove video">
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
            <span class="upload-dropzone-icon"><x-ui.icon name="video" :size="24" /></span>
            <span class="upload-dropzone-title">Add videos</span>
            <span class="upload-dropzone-hint">
                MP4, WebM or MOV · up to {{ round($maxKb / 1024) }} MB each ·
                {{ $remaining }} {{ Str::plural('slot', $remaining) }} left
            </span>

            <input
                type="file"
                class="upload-input"
                name="{{ $name }}[]"
                accept="video/mp4,video/webm,video/quicktime"
                multiple
                data-upload-input
            >
        </label>

        <div class="upload-previews" data-upload-previews></div>
        <p class="upload-counter" data-upload-counter></p>
    @else
        <p class="field-hint">
            You've reached the {{ $max }} video limit. Remove one to add another.
        </p>
    @endif

    @error($name)<p class="field-error">{{ $message }}</p>@enderror
    @error($name.'.*')<p class="field-error">{{ $message }}</p>@enderror
</div>
