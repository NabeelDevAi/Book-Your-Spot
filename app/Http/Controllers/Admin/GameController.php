<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GameStatus;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * FR-3.3 -- the master category list.
 *
 * Admin-only by design. If owners could invent categories the taxonomy would
 * fragment into "PS5" / "Playstation 5" / "PS 5" and the customer-facing filter
 * would stop working, which is the entire reason this list is centralised.
 */
class GameController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.games.index', [
            'games' => Game::query()
                ->withCount('businessGames')
                ->ordered()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:games,name'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:40'],
        ]);

        $game = Game::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'description' => $validated['description'] ?? null,
            'icon' => $validated['icon'] ?? null,
            'status' => GameStatus::Active,
            'sort_order' => (int) Game::max('sort_order') + 1,
        ]);

        $this->audit->log('game.created', $game, meta: ['name' => $game->name]);

        return back()->with('success', "\"{$game->name}\" added to the category list.");
    }

    public function update(Request $request, Game $game): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:games,name,'.$game->id],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $originalName = $game->name;

        // The slug is deliberately NOT regenerated on rename: it is in every
        // shared search URL, and changing it would silently break links
        // customers and venues have already sent each other.
        $game->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        $this->audit->log('game.renamed', $game, meta: ['from' => $originalName, 'to' => $game->name]);

        return back()->with('success', 'Category updated.');
    }

    /**
     * Deactivating hides a category from new selections but leaves every
     * existing Business-Game link intact (FR-3.3), so live venues keep working.
     */
    public function deactivate(Game $game): RedirectResponse
    {
        $game->update(['status' => GameStatus::Inactive]);

        $this->audit->log(AuditLogger::GAME_DEACTIVATED, $game, meta: ['name' => $game->name]);

        $inUse = $game->businessGames()->count();

        $message = "\"{$game->name}\" is hidden from new listings.";

        if ($inUse > 0) {
            // Existing links survive deactivation (FR-3.3), so say so rather
            // than letting the admin assume venues just lost their category.
            $message .= ' '.$inUse.' existing '
                .Str::plural('venue', $inUse).' keep it.';
        }

        return back()->with('success', $message);
    }

    public function activate(Game $game): RedirectResponse
    {
        $game->update(['status' => GameStatus::Active]);

        $this->audit->log('game.activated', $game, meta: ['name' => $game->name]);

        return back()->with('success', "\"{$game->name}\" is selectable again.");
    }
}
