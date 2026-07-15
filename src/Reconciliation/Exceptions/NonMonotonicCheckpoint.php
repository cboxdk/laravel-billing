<?php

declare(strict_types=1);

namespace Cbox\Billing\Reconciliation\Exceptions;

use RuntimeException;

/**
 * A checkpoint was asked to advance backwards. A checkpoint's high-water marks
 * (the reconciled ceiling, the aged-out boundary, the aged cumulative total, and the
 * posting sequence) only ever move forward — regressing one would re-open an
 * already-settled region and risk double-posting. The reconciler clamps every bound
 * with `max(...)` so this can only fire on a programming error, never in normal flow.
 */
class NonMonotonicCheckpoint extends RuntimeException {}
