<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Exceptions;

use Cbox\Billing\Subscription\SubscriptionManager;
use DateTimeImmutable;
use RuntimeException;

/**
 * Raised when {@see SubscriptionManager::convertTrial()} is asked to convert a trial
 * to a paying subscription BEFORE its `trialEndsAt` — which would charge the customer
 * earlier than the trial they were promised. The normal conversion path honours the
 * boundary; an intentional early conversion (e.g. the customer upgrades mid-trial)
 * goes through {@see SubscriptionManager::forceConvertTrial()} instead.
 *
 * Carries the trial end and the attempted conversion instant so a caller can surface a
 * precise reason.
 */
class TrialNotEnded extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly DateTimeImmutable $trialEndsAt,
        public readonly DateTimeImmutable $attemptedAt,
    ) {
        parent::__construct($message);
    }

    public static function before(DateTimeImmutable $trialEndsAt, DateTimeImmutable $attemptedAt): self
    {
        return new self(
            message: sprintf(
                'Cannot convert a trial at [%s]: it runs until [%s]. Use forceConvertTrial() for an intentional early conversion.',
                $attemptedAt->format(DateTimeImmutable::ATOM),
                $trialEndsAt->format(DateTimeImmutable::ATOM),
            ),
            trialEndsAt: $trialEndsAt,
            attemptedAt: $attemptedAt,
        );
    }
}
