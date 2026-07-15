<?php

declare(strict_types=1);

namespace Cbox\Billing\Seller\ValueObjects;

use Cbox\Geo\ValueObjects\CountryCode;
use Cbox\Tax\ValueObjects\SellerRegistration;
use Cbox\Tax\ValueObjects\SellerRegistrations;

/**
 * A legal selling entity — the "seller of record" that issues an invoice. A
 * business can have several (a Danish ApS, a UK Ltd, a US Inc), each with its own
 * legal identity, tax registrations and invoice-number sequence. The entity that
 * issues the invoice drives the tax outcome, so it produces the tax engine's
 * seller-registration input via {@see toSellerRegistrations()}.
 */
readonly class SellerEntity
{
    /**
     * @param  list<TaxRegistration>  $taxRegistrations
     */
    public function __construct(
        public string $id,
        public string $legalName,
        public string $registrationNumber,
        public CountryCode $establishment,
        public string $defaultCurrency,
        public string $invoicePrefix,
        public array $taxRegistrations = [],
    ) {}

    public function isRegisteredIn(CountryCode $country): bool
    {
        if ($this->establishment->equals($country)) {
            return true;
        }

        foreach ($this->taxRegistrations as $registration) {
            if ($registration->country->equals($country)) {
                return true;
            }
        }

        return false;
    }

    public function toSellerRegistrations(): SellerRegistrations
    {
        $registrations = [];

        foreach ($this->taxRegistrations as $registration) {
            $registrations[] = new SellerRegistration(
                $registration->country,
                $registration->subdivision,
                $registration->scheme,
            );
        }

        return new SellerRegistrations($this->establishment, $registrations);
    }
}
