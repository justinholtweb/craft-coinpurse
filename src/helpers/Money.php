<?php

namespace justinholtweb\coinpurse\helpers;

use craft\commerce\Plugin as Commerce;

/**
 * Minor-unit conversion.
 *
 * Every wallet API on earth talks in the currency's smallest unit — cents, pence, yen — and
 * Commerce talks in floats. Getting the conversion wrong is not a rounding bug, it is a hundredfold
 * overcharge, so it happens in exactly one place and uses `bcmath` semantics rather than
 * `(int)($amount * 100)`, which turns 8.30 into 829 on a fair number of builds.
 */
abstract class Money
{
    /**
     * How many minor units make up one major unit of the given currency. Zero-decimal currencies
     * such as JPY return 1; three-decimal ones such as KWD return 1000.
     */
    public static function subunitFactor(string $currencyIso): int
    {
        $currencies = Commerce::getInstance()->getCurrencies();
        $currency = $currencies->getCurrencyByIso($currencyIso);

        if ($currency === null) {
            return 100;
        }

        return 10 ** (int)$currencies->getSubunitFor($currency);
    }

    /**
     * Convert a Commerce amount into the wallet's minor units.
     */
    public static function toMinor(float|string $amount, string $currencyIso): int
    {
        $factor = self::subunitFactor($currencyIso);

        // `number_format` first so that a float like 8.299999999 becomes the string "8.30" before
        // it is multiplied; bcmul on the raw float would carry the error into the result.
        $decimals = (int)round(log10($factor));
        $normalised = number_format((float)$amount, $decimals, '.', '');

        return (int)bcmul($normalised, (string)$factor, 0);
    }

    /**
     * Convert minor units back into a Commerce amount.
     */
    public static function toMajor(int $minor, string $currencyIso): float
    {
        return (float)bcdiv((string)$minor, (string)self::subunitFactor($currencyIso), 6);
    }
}
