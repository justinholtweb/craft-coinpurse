<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * The monetary shape of an express session at one instant: what the sheet shows, and what the
 * order will charge.
 *
 * Produced only by `services\Quotes::build()`. Nothing else in the plugin is allowed to add up
 * money, which is the whole point — a wallet sheet that displays one total and charges another is
 * the worst bug this plugin could ship, and there is exactly one place it could come from.
 */
class Quote extends Model
{
    public string $currency = 'USD';

    /**
     * @var int How many decimal places this currency has. Apple Pay and Google Pay both want
     *          amounts as major-unit decimal strings, so the browser needs this to format the
     *          minor-unit amounts below without a currency table of its own.
     */
    public int $decimals = 2;
    public string $countryCode = 'US';
    public string $label = '';

    /** @var int The order total, in minor units. */
    public int $total = 0;

    /** @var QuoteLine[] */
    public array $lines = [];

    /** @var ShippingOption[] */
    public array $shippingOptions = [];

    /** @var string|null The handle of the shipping method currently selected on the order. */
    public ?string $selectedShippingOption = null;

    /** @var bool Whether the order still needs a shipping address before it can be priced. */
    public bool $needsShippingAddress = false;

    /**
     * @var string|null Set when the order cannot be shipped to the address the wallet supplied.
     *                  Drivers turn this into the wallet's own "cannot ship here" state.
     */
    public ?string $shippingError = null;

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'currency' => $this->currency,
            'decimals' => $this->decimals,
            'countryCode' => $this->countryCode,
            'label' => $this->label,
            'total' => $this->total,
            'lines' => array_map(static fn(QuoteLine $l) => $l->toArray(), $this->lines),
            'shippingOptions' => array_map(static fn(ShippingOption $o) => $o->toArray(), $this->shippingOptions),
            'selectedShippingOption' => $this->selectedShippingOption,
            'needsShippingAddress' => $this->needsShippingAddress,
            'shippingError' => $this->shippingError,
        ];
    }
}
