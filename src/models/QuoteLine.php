<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * One line in the wallet sheet. Amounts are in the currency's minor units, because that is what
 * every wallet API wants and converting once is safer than converting per driver.
 */
class QuoteLine extends Model
{
    public const TYPE_ITEM = 'item';
    public const TYPE_SUBTOTAL = 'subtotal';
    public const TYPE_SHIPPING = 'shipping';
    public const TYPE_TAX = 'tax';
    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_ADJUSTMENT = 'adjustment';

    public string $type = self::TYPE_ITEM;
    public string $name = '';
    public int $amount = 0;

    /** @var bool A line whose amount is not yet known — Apple Pay renders these as "Pending". */
    public bool $pending = false;

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'amount' => $this->amount,
            'pending' => $this->pending,
        ];
    }
}
