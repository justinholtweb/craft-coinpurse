<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\base\Gateway;
use craft\commerce\Plugin as Commerce;
use justinholtweb\coinpurse\base\WalletDriverInterface;
use justinholtweb\coinpurse\drivers\RawDriver;
use justinholtweb\coinpurse\drivers\StripeDriver;
use justinholtweb\coinpurse\events\RegisterDriversEvent;
use justinholtweb\coinpurse\models\Settings;
use justinholtweb\coinpurse\Plugin;

/**
 * Which driver charges which gateway.
 */
class Drivers extends Component
{
    /**
     * @event RegisterDriversEvent Raised while collecting available wallet drivers.
     */
    public const EVENT_REGISTER_DRIVERS = 'registerDrivers';

    /** @var WalletDriverInterface[]|null */
    private ?array $_drivers = null;

    /**
     * @return WalletDriverInterface[] Keyed by handle.
     */
    public function all(): array
    {
        if ($this->_drivers !== null) {
            return $this->_drivers;
        }

        $event = new RegisterDriversEvent([
            'drivers' => [
                new StripeDriver(),
                new RawDriver(),
            ],
        ]);

        $this->trigger(self::EVENT_REGISTER_DRIVERS, $event);

        $drivers = [];

        foreach ($event->drivers as $driver) {
            $drivers[$driver::handle()] = $driver;
        }

        return $this->_drivers = $drivers;
    }

    public function getByHandle(string $handle): ?WalletDriverInterface
    {
        return $this->all()[$handle] ?? null;
    }

    /**
     * The gateway express payments are charged through, or null if none is configured.
     */
    public function getGateway(): ?Gateway
    {
        $gatewayId = Plugin::getInstance()->getSettings()->gatewayId;

        if (!$gatewayId) {
            return null;
        }

        try {
            return Commerce::getInstance()->getGateways()->getGatewayById($gatewayId);
        } catch (\Throwable $e) {
            Craft::warning('Coin Purse could not load its gateway: ' . $e->getMessage(), __METHOD__);

            return null;
        }
    }

    /**
     * The driver that will actually be used, given the configured gateway and the `driver` setting.
     *
     * `auto` prefers a driver that natively understands the gateway — Stripe's own Express Checkout
     * Element gets Link, Apple Pay's newer sheet features and Stripe's fraud signals, none of which
     * the raw driver can offer — and falls back to the raw wallet protocols for everything else.
     */
    public function resolve(?Gateway $gateway = null): ?WalletDriverInterface
    {
        $gateway = $gateway ?? $this->getGateway();

        if (!$gateway) {
            return null;
        }

        $configured = Plugin::getInstance()->getSettings()->driver;

        if ($configured !== Settings::DRIVER_AUTO) {
            $driver = $this->getByHandle($configured);

            return $driver && $driver->supportsGateway($gateway) ? $driver : null;
        }

        foreach ($this->all() as $driver) {
            if ($driver->supportsGateway($gateway)) {
                return $driver;
            }
        }

        return null;
    }

    /**
     * Options for the settings screen's driver dropdown.
     */
    public function getDriverOptions(): array
    {
        $options = [
            ['label' => Craft::t('coinpurse', 'Automatic'), 'value' => Settings::DRIVER_AUTO],
        ];

        foreach ($this->all() as $handle => $driver) {
            $options[] = ['label' => $driver::displayName(), 'value' => $handle];
        }

        return $options;
    }
}
