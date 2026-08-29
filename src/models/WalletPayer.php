<?php

namespace justinholtweb\coinpurse\models;

use craft\base\Model;

/**
 * The contact details a wallet hands over: who is buying, and how to reach them.
 */
class WalletPayer extends Model
{
    public ?string $name = null;
    public ?string $email = null;
    public ?string $phone = null;

    public function getFirstName(): ?string
    {
        if (!$this->name) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return $parts[0] ?? null;
    }

    public function getLastName(): ?string
    {
        if (!$this->name) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($this->name)) ?: [];

        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    }
}
