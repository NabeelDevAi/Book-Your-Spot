<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessImage;
use App\Models\Spot;
use App\Models\SpotImage;
use App\Services\ImageManager;
use Illuminate\Http\RedirectResponse;

class ImageController extends Controller
{
    public function __construct(private readonly ImageManager $images) {}

    public function destroyBusinessImage(Business $business, BusinessImage $image): RedirectResponse
    {
        $this->authorize('update', $business);
        abort_unless($image->business_id === $business->id, 404);

        $isVideo = $image->isVideo();
        $this->images->delete($image);

        return back()->with('success', $isVideo ? 'Video removed.' : 'Photo removed.');
    }

    public function destroySpotImage(Business $business, Spot $spot, SpotImage $image): RedirectResponse
    {
        $this->authorize('update', $spot);
        abort_unless($spot->business_id === $business->id && $image->spot_id === $spot->id, 404);

        $isVideo = $image->isVideo();
        $this->images->delete($image);

        return back()->with('success', $isVideo ? 'Video removed.' : 'Photo removed.');
    }
}
