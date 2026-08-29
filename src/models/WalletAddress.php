<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * An address as it arrives from a wallet, before it becomes a Commerce address.
 *
 * Wallets deliberately redact addresses while the sheet is open: the shipping-address-change event
 * in every one of these APIs may carry nothing but a country, a state and a postal code, because
 * that is all a shipping quote genuinely needs and handing over a street address to a site the
 * customer has not yet bought from is a privacy leak. The full address only appears on
 * authorisation. `$redacted` records which of the two this is, so callers stop treating a missing
 * street line as a validation failure.
 */
class WalletAddress extends Model
{
    public ?string $firstName = null;
    public ?string $lastName = null;
    public ?string $organization = null;
    public ?string $addressLine1 = null;
    public ?string $addressLine2 = null;
    public ?string $addressLine3 = null;
    public ?string $locality = null;
    public ?string $administrativeArea = null;
    public ?string $postalCode = null;
    public ?string $countryCode = null;

    public bool $redacted = false;

    public function getFullName(): ?string
    {
        $name = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The attribute array Commerce wants.
     *
     * It has to be an array rather than an Address element: `Order::setShippingAddress()` throws
     * "Can not set a shipping address on the order that is not owned by the order" when handed an
     * element it does not own, and building an owned one by hand means duplicating what Commerce
     * already does with an array.
     */
    public function toCommerceAttributes(): array
    {
        $attributes = [
            'countryCode' => $this->countryCode ?: null,
            'administrativeArea' => $this->administrativeArea ?: null,
            'locality' => $this->locality ?: null,
            'postalCode' => $this->postalCode ?: null,
            'addressLine1' => $this->addressLine1 ?: null,
            'addressLine2' => $this->addressLine2 ?: null,
            'addressLine3' => $this->addressLine3 ?: null,
            'organization' => $this->organization ?: null,
            'fullName' => $this->getFullName(),
        ];

        return array_filter($attributes, static fn($value) => $value !== null && $value !== '');
    }

    /**
     * Whether there is enough here to price shipping. A country alone is not enough for most
     * shipping rules, but it is all some wallets give for some countries, so this is deliberately
     * permissive: Commerce's own matchers decide what they can and cannot rate.
     */
    public function isRateable(): bool
    {
        return (bool)$this->countryCode;
    }

    /**
     * Whether this is a complete address that an order can actually be shipped to.
     */
    public function isComplete(): bool
    {
        return (bool)$this->countryCode && (bool)$this->addressLine1;
    }
}
