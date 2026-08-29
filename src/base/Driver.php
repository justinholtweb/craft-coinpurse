<?php

namespace justinholtweb\coinpurse\base;

use craft\base\Component;
use craft\commerce\base\Gateway;
use craft\commerce\models\Transaction;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\Plugin;
use justinholtweb\coinpurse\models\Settings;

/**
 * Shared ground for the bundled drivers.
 */
abstract class Driver extends Component implements WalletDriverInterface
{
    public function getProblems(Gateway $gateway): array
    {
        return [];
    }

    public function getPaymentResult(
        ExpressSession $session,
        ?Transaction $transaction,
        ?string $redirect,
        array $redirectData,
    ): array {
        return ['confirmed' => true];
    }

    protected function settings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    /**
     * The wallets this driver should offer, intersected with what the merchant has switched on.
     *
     * @param string[] $supported
     * @return string[]
     */
    protected function enabledWallets(array $supported): array
    {
        return array_values(array_intersect($supported, $this->settings()->getEnabledWallets()));
    }
}
