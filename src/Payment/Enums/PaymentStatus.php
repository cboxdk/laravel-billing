<?php

declare(strict_types=1);

namespace Cbox\Billing\Payment\Enums;

/**
 * Outcome of a payment attempt. `Pending` covers gateways that settle out of band
 * (bank transfer, manual capture); `RequiresAction` covers flows that need the
 * customer (e.g. 3-D Secure).
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case RequiresAction = 'requires_action';

    public function isSettled(): bool
    {
        return $this === self::Succeeded;
    }
}
