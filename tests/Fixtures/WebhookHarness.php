<?php

declare(strict_types=1);

namespace Cbox\Billing\Tests\Fixtures;

use Cbox\Billing\Payment\Testing\InteractsWithWebhooks;

/**
 * Composition site so the shippable webhook testing trait is type-checked by PHPStan
 * (tests/Fixtures is on the analysis path).
 */
class WebhookHarness
{
    use InteractsWithWebhooks;
}
