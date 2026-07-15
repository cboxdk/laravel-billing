<?php

declare(strict_types=1);

namespace Cbox\Billing\Seller\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Geo\ValueObjects\SubdivisionCode;

/**
 * A tax registration a selling entity holds in a jurisdiction (a VAT number, a US
 * state permit, an OSS registration). It carries the legal number for the invoice
 * and maps to the tax engine's seller-registration input.
 */
readonly class TaxRegistration
{
    public function __construct(
        public CountryCode $country,
        public string $number,
        public ?SubdivisionCode $subdivision = null,
        public ?string $scheme = null,
    ) {}
}
