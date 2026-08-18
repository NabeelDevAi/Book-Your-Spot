<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;

/**
 * A web-reachable equivalent of `php artisan optimize` / `optimize:clear`.
 *
 * Exists because the target host does not reliably offer shell/SSH access on
 * every deploy (the same reasoning behind writing uploads to public/uploads/
 * instead of relying on `storage:link`) -- so after pulling new code, an Admin
 * needs a way to re-cache config/routes/views, or clear a stale cache that's
 * serving old behaviour, without a terminal. Restricted to Admin accounts:
 * this is an operational lever, not something any logged-in user should reach.
 */
class SystemController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.system.index');
    }

    /** Cache config, routes, views and events -- what you'd run right after a deploy. */
    public function optimize(): RedirectResponse
    {
        Artisan::call('optimize');

        $this->audit->log('system.optimized', meta: ['output' => trim(Artisan::output())]);

        return back()->with('success', 'Optimized: config, routes, views and events are now cached.');
    }

    /** Clear every cache -- the fix when stale cached config/routes/views is serving old behaviour. */
    public function clearCache(): RedirectResponse
    {
        Artisan::call('optimize:clear');

        $this->audit->log('system.cache_cleared', meta: ['output' => trim(Artisan::output())]);

        return back()->with('success', 'Cleared: config, routes, views, events and the application cache.');
    }
}
