<?php

namespace App\Console\Commands;

use App\Models\Topup;
use App\Services\Payment\TopupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Catches top-ups whose webhook never arrived.
 *
 * Webhooks get dropped -- rarely, but this handles real money, and the failure
 * mode is a customer whose card was charged and whose balance never moved. That
 * is the single worst bug this system can have, so it gets a sweep of its own
 * rather than relying on someone noticing.
 *
 * Asks the gateway what actually happened and applies it through the same
 * idempotent `fulfil()` the webhook uses, so a late-arriving webhook and this
 * command cannot both credit the same top-up.
 */
class ReconcileTopups extends Command
{
    protected $signature = 'topups:reconcile {--minutes=30 : How stale a pending top-up must be}';

    protected $description = 'Ask the payment gateway about top-ups that never resolved';

    public function handle(TopupService $topups): int
    {
        $stale = Topup::stale((int) $this->option('minutes'))
            ->whereNotNull('gateway_reference')
            ->orderBy('id')
            ->get();

        if ($stale->isEmpty()) {
            return self::SUCCESS;
        }

        $resolved = 0;

        foreach ($stale as $topup) {
            try {
                if ($topups->reconcile($topup)) {
                    $resolved++;
                    $this->info("Resolved top-up #{$topup->id} ({$topup->fresh()->status->value}).");
                }
            } catch (Throwable $e) {
                // One unreachable intent must not stop the sweep -- the next
                // top-up in the list may be somebody's missing money.
                report($e);
                $this->error("Could not reconcile top-up #{$topup->id}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$stale->count()} pending top-up(s), resolved {$resolved}.");

        return self::SUCCESS;
    }
}
