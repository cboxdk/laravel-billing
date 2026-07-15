<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Console;

use Cbox\Billing\Reconciliation\Contracts\Reconciler;
use Cbox\Billing\Reconciliation\ValueObjects\ReconcileTarget;
use Illuminate\Console\Command;

/**
 * Thin console entry point over the {@see Reconciler}. Targets are passed as
 * `org:meter` pairs; the command parses them, delegates the whole cycle to the
 * contract, and renders the outcome. All arithmetic and the guards live in the
 * reconciler — this adapter only marshals input and output.
 *
 * A host with a registry of active `(org, meter)` entities schedules this (or calls
 * the {@see Reconciler} contract directly) on the drift-window cadence.
 */
class ReconcileUsageCommand extends Command
{
    protected $signature = 'billing:reconcile
        {--target=* : Entities to reconcile as org:meter pairs}';

    protected $description = 'Reconcile durable usage into the ledger by cumulative delta (ADR-0003).';

    public function handle(Reconciler $reconciler): int
    {
        $targets = [];

        $pairs = $this->option('target');
        $pairs = is_array($pairs) ? $pairs : [];

        foreach ($pairs as $pair) {
            if (! is_string($pair) || ! str_contains($pair, ':')) {
                $this->error("Ignoring malformed target [{$this->stringify($pair)}]; expected org:meter.");

                continue;
            }

            [$org, $meter] = explode(':', $pair, 2);
            $targets[] = new ReconcileTarget($org, $meter);
        }

        if ($targets === []) {
            $this->warn('No targets given (--target=org:meter). Nothing to reconcile.');

            return self::SUCCESS;
        }

        $report = $reconciler->reconcile($targets);

        foreach ($report->reconciled as $entry) {
            $this->line(sprintf(
                '<info>%s/%s</info> meter %+d aged_out %+d (through %d)',
                $entry->target->org,
                $entry->target->meter,
                $entry->meterDelta,
                $entry->agedDelta,
                $entry->reconciledThroughMs,
            ));
        }

        foreach ($report->skipped as $failure) {
            $this->error(sprintf(
                '%s/%s skipped: %s',
                $failure->target->org,
                $failure->target->meter,
                $failure->error->getMessage(),
            ));
        }

        return $report->skipped === [] ? self::SUCCESS : self::FAILURE;
    }

    private function stringify(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
