<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * NFR-6 / SRS 9.20 -- the moderation trail.
 *
 * Read-only by construction: there is no edit or delete route, because a log
 * that can be altered is worth nothing in the disputes it exists to settle.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::query()
            ->with('actor')
            ->when(filled($request->query('action')), fn (Builder $q) => $q->where('action', $request->query('action')))
            ->when(filled($request->query('actor')), fn (Builder $q) => $q->where('actor_id', $request->query('actor')))
            ->when($request->boolean('system_only'), fn (Builder $q) => $q->whereNull('actor_id'))
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'actions' => AuditLog::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
