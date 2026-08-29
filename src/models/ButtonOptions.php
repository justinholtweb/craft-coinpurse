<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * The normalised, signable configuration of one wallet button.
 *
 * The signed half of this — mode, items, shipping and detail requirements, site and gateway — is
 * what the express endpoints will actually honour. The unsigned half is presentation, and lives
 * only in the browser.
 *
 * This is the fix for the flaw in the plugin Coin Purse replaces, whose endpoints charged for
 * whatever purchasable IDs and quantities the browser posted. A hostile client could substitute a
 * cheap variant's ID for an expensive one's, or set a quantity of zero, and the server would price
 * and charge it without complaint.
 */
class ButtonOptions extends Model
{
    public const MODE_CART = 'cart';

    /**
     * The placeholder rate the browser shows before a shipping address is known.
     *
     * Both Apple Pay and Google Pay — and Stripe's element on their behalf — refuse to open a
     * sheet that asks for a shipping address but offers no rates at all, and they refuse it with
     * an error the customer sees. The placeholder keeps the sheet open for the one round trip it
     * takes to price the address the customer picks. It must never reach an order.
     */
    public const PENDING_SHIPPING_OPTION = '__pending__';

    public const MODE_ITEMS = 'items';


    public string $mode = self::MODE_ITEMS;

    /**
     * @var array<int, array{id: int, qty: int, options: array, note: string}>
     */
    public array $items = [];

    /**
     * @var string|false `shipping`, `delivery`, `pickup`, or false for a purchase that needs no
     *                   address at all (digital goods).
     */
    public string|false $requestShipping = false;

    /** @var string[] Any of `name`, `email`, `phone`. */
    public array $requestDetails = [];

    public ?int $siteId = null;
    public ?int $gatewayId = null;

    /** @var int Unix timestamp after which this button's payload is refused. */
    public int $expires = 0;

    // Presentation. Not signed, not trusted, never used server-side.
    public array $onComplete = [];
    public array $style = [];
    public ?string $js = null;

    /**
     * The canonical array that gets signed. Key order is fixed and values are cast, so that two
     * runs over the same button produce byte-identical input to the MAC.
     */
    public function signablePayload(): array
    {
        $items = [];

        foreach ($this->items as $item) {
            $options = $item['options'] ?? [];
            ksort($options);

            $items[] = [
                'id' => (int)($item['id'] ?? 0),
                'qty' => (int)($item['qty'] ?? 1),
                'options' => $options,
                'note' => (string)($item['note'] ?? ''),
            ];
        }

        return [
            'mode' => $this->mode,
            'items' => $items,
            'requestShipping' => $this->requestShipping === false ? false : (string)$this->requestShipping,
            'requestDetails' => $this->normalisedDetails(),
            'siteId' => $this->siteId,
            'gatewayId' => $this->gatewayId,
            'expires' => $this->expires,
        ];
    }

    /**
     * @return string[]
     */
    public function normalisedDetails(): array
    {
        $details = array_values(array_unique(array_map('strval', $this->requestDetails)));
        sort($details);

        return $details;
    }

    public function wantsDetail(string $detail): bool
    {
        return in_array($detail, $this->requestDetails, true);
    }

    public function needsShipping(): bool
    {
        return $this->requestShipping !== false;
    }

    /**
     * Apple Pay's `shippingType`, which changes the wording in the sheet ("Ship to", "Deliver to",
     * "Pick up at"). Google Pay and Stripe have no equivalent and ignore it.
     */
    public function shippingType(): string
    {
        return match ($this->requestShipping) {
            'delivery' => 'delivery',
            'pickup' => 'storePickup',
            default => 'shipping',
        };
    }

    public function isExpired(): bool
    {
        return $this->expires > 0 && $this->expires < time();
    }
}
