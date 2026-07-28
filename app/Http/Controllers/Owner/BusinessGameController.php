<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * FR-2.3 -- an Owner picks which master categories their venue offers.
 *
 * Owners cannot invent categories. That restriction is the whole reason the
 * category filter works: without it the taxonomy fragments into "PS5",
 * "Playstation 5" and "PS 5", and a customer filtering for one misses the others.
 */
class BusinessGameController extends Controller
{
    public function edit(Business $business): View
    {
        $this->authorize('manage', $business);

        return view('owner.businesses.games', [
            'business' => $business,
            'games' => Game::active()->ordered()->get(),
            // Categories already selected, including any whose master entry has
            // since been deactivated -- those stay linked (FR-3.3).
            'selected' => $business->businessGames()->pluck('game_id')->all(),
            'spotCounts' => $business->spots()
                ->selectRaw('business_game_id, count(*) as total')
                ->groupBy('business_game_id')
                ->pluck('total', 'business_game_id'),
            'businessGames' => $business->businessGames()->with('game')->get()->keyBy('game_id'),
        ]);
    }

    public function update(Request $request, Business $business): RedirectResponse
    {
        $this->authorize('manage', $business);

        $validated = $request->validate([
            'games' => ['nullable', 'array'],
            'games.*' => ['integer', 'exists:games,id'],
        ]);

        $selected = collect($validated['games'] ?? [])->map(fn ($id) => (int) $id);

        // Only currently-active categories may be newly linked. A deactivated
        // category keeps its existing links but must not gain new ones.
        $selectable = Game::active()->pluck('id');
        $existing = $business->businessGames()->pluck('game_id');
        $selected = $selected->filter(
            fn (int $id) => $selectable->contains($id) || $existing->contains($id)
        );

        $blocked = [];

        DB::transaction(function () use ($business, $selected, $existing, &$blocked) {
            foreach ($selected->diff($existing) as $gameId) {
                BusinessGame::create([
                    'business_id' => $business->id,
                    'game_id' => $gameId,
                ]);
            }

            foreach ($existing->diff($selected) as $gameId) {
                $businessGame = $business->businessGames()->where('game_id', $gameId)->first();

                if (! $businessGame) {
                    continue;
                }

                // Removing a category that still has spots would orphan them and
                // their booking history, so the spots must be dealt with first.
                if ($businessGame->spots()->withTrashed()->exists()) {
                    $blocked[] = $businessGame->game->name;

                    continue;
                }

                $businessGame->delete();
            }
        });

        if ($blocked !== []) {
            return back()->with('warning', sprintf(
                'Saved, but %s %s still %s spots. Delete or move those spots before removing the category.',
                implode(' and ', $blocked),
                count($blocked) === 1 ? 'has' : 'have',
                count($blocked) === 1 ? 'its' : 'their',
            ));
        }

        return redirect()
            ->route('owner.businesses.spots.index', $business)
            ->with('success', 'Categories updated. Add the spots people can book.');
    }
}
