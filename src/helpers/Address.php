<?php

namespace justinholtweb\coinpurse\helpers;

use justinholtweb\coinpurse\models\WalletAddress;

/**
 * Wallet addresses in, one `WalletAddress` out.
 *
 * The three wallet APIs disagree about almost everything: Apple Pay sends `addressLines` as an
 * array and the state as `administrativeArea`, Google Pay sends `address1`/`address2` and calls the
 * state `administrativeArea` too but the town `locality`, and Stripe's Express Checkout Element
 * normalises to a `line1`/`line2`/`city`/`state` shape borrowed from the Payment Request API.
 * They also disagree about case: `countryCode` arrives lower-case from some browsers.
 */
abstract class Address
{
    /**
     * Stripe / Payment Request API shape.
     */
    public static function fromStripe(array $address, ?string $name = null): WalletAddress
    {
        $wallet = new WalletAddress([
            'addressLine1' => self::str($address['line1'] ?? null),
            'addressLine2' => self::str($address['line2'] ?? null),
            'locality' => self::str($address['city'] ?? null),
            'administrativeArea' => self::str($address['state'] ?? null),
            'postalCode' => self::str($address['postal_code'] ?? $address['postalCode'] ?? null),
            'countryCode' => self::country($address['country'] ?? null),
            'organization' => self::str($address['organization'] ?? null),
        ]);

        self::applyName($wallet, $name ?? ($address['name'] ?? null));

        $wallet->redacted = !$wallet->addressLine1;

        return $wallet;
    }

    /**
     * Apple Pay JS `ApplePayPaymentContact`.
     */
    public static function fromApplePay(array $contact): WalletAddress
    {
        $lines = $contact['addressLines'] ?? [];
        $lines = is_array($lines) ? array_values($lines) : [];

        $wallet = new WalletAddress([
            'firstName' => self::str($contact['givenName'] ?? null),
            'lastName' => self::str($contact['familyName'] ?? null),
            'addressLine1' => self::str($lines[0] ?? null),
            'addressLine2' => self::str($lines[1] ?? null),
            'addressLine3' => self::str($lines[2] ?? null),
            'locality' => self::str($contact['locality'] ?? null),
            'administrativeArea' => self::str($contact['administrativeArea'] ?? null),
            'postalCode' => self::str($contact['postalCode'] ?? null),
            'countryCode' => self::country($contact['countryCode'] ?? null),
        ]);

        $wallet->redacted = !$wallet->addressLine1;

        return $wallet;
    }

    /**
     * Google Pay `IntermediateAddress` (during the sheet) or the full `Address` (on authorisation).
     */
    public static function fromGooglePay(array $address): WalletAddress
    {
        $wallet = new WalletAddress([
            'addressLine1' => self::str($address['address1'] ?? null),
            'addressLine2' => self::str($address['address2'] ?? null),
            'addressLine3' => self::str($address['address3'] ?? null),
            'locality' => self::str($address['locality'] ?? null),
            'administrativeArea' => self::str($address['administrativeArea'] ?? null),
            'postalCode' => self::str($address['postalCode'] ?? null),
            'countryCode' => self::country($address['countryCode'] ?? null),
        ]);

        self::applyName($wallet, $address['name'] ?? null);

        // Google's intermediate address carries a `postalCode` and nothing else identifying, and
        // sets no street lines at all.
        $wallet->redacted = !$wallet->addressLine1;

        return $wallet;
    }

    private static function applyName(WalletAddress $wallet, ?string $name): void
    {
        $name = self::str($name);

        if (!$name || ($wallet->firstName && $wallet->lastName)) {
            return;
        }

        $parts = preg_split('/\s+/', $name) ?: [];

        $wallet->firstName = $parts[0] ?? null;
        $wallet->lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    }

    private static function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private static function country(mixed $value): ?string
    {
        $value = self::str($value);

        return $value ? strtoupper(substr($value, 0, 2)) : null;
    }
}
