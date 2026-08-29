<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * A shipping method as the wallet sheet sees it. `id` is the Commerce shipping method handle —
 * the wallet hands it straight back on selection, so there is no lookup table to keep in step.
 */
class ShippingOption extends Model
{
    public string $id = '';
    public string $label = '';
    public string $detail = '';
    public int $amount = 0;

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'detail' => $this->detail,
            'amount' => $this->amount,
        ];
    }
}
