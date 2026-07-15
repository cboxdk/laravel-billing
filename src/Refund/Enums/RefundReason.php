<?php

declare(strict_types=1);

namespace Cbox\Billing\Refund\Enums;

/**
 * Why a voluntary refund was issued. Travels onto the credit note for audit and
 * reporting. A chargeback is not a refund reason — it is a forced reversal recorded
 * separately (see {@see ReversalKind}).
 */
enum RefundReason: string
{
    case Requested = 'requested';
    case Duplicate = 'duplicate';
    case ServiceIssue = 'service_issue';
    case Goodwill = 'goodwill';
    case OrderCancelled = 'order_cancelled';
}
