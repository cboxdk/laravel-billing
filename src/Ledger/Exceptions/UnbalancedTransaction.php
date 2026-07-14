<?php

declare(strict_types=1);

namespace Cbox\Billing\Ledger\Exceptions;

use RuntimeException;

/**
 * A ledger transaction whose debits do not equal its credits (or that mixes
 * currencies). Double-entry only ever posts balanced transactions.
 */
class UnbalancedTransaction extends RuntimeException {}
