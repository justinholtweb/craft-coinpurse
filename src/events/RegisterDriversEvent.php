<?php

namespace justinholtweb\coinpurse\events;

use justinholtweb\coinpurse\base\WalletDriverInterface;
use yii\base\Event;

/**
 * Raised while Coin Purse is collecting the drivers it can charge through.
 */
class RegisterDriversEvent extends Event
{
    /** @var WalletDriverInterface[] */
    public array $drivers = [];
}
