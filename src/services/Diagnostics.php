<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use justinholtweb\coinpurse\drivers\RawDriver;
use justinholtweb\coinpurse\models\Check;
use justinholtweb\coinpurse\Plugin;

/**
 * "Why is there no button?"
 *
 * This is the most useful thing in the plugin, and the reason is worth writing down: a wallet
 * button that is misconfigured does not throw, log or render an error. `ApplePaySession` simply is
 * not defined, `isReadyToPay` simply resolves false, and the page shows nothing. The merchant's own
 * phone shows the button perfectly, because the merchant has a card in their wallet and a verified
 * domain in their Stripe account. Every failure in this list is one somebody would otherwise have
 * spent an afternoon on.
 */
class Diagnostics extends Component
{
    /**
     * @return Check[]
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->commerceCheck();

        if (!Plugin::commerceIsReady()) {
            return $checks;
        }

        $checks[] = $this->httpsCheck();
        $checks[] = $this->gatewayCheck();
        $checks[] = $this->driverCheck();
        $checks[] = $this->walletsCheck();

        foreach ($this->driverProblemChecks() as $check) {
            $checks[] = $check;
        }

        $checks[] = $this->shippingCheck();
        $checks[] = $this->domainAssociationCheck();

        return array_values(array_filter($checks));
    }

    /**
     * @return Check[]
     */
    public function failures(): array
    {
        return array_values(array_filter($this->run(), static fn(Check $c) => $c->isFail()));
    }

    // ---------------------------------------------------------------- Checks

    private function commerceCheck(): Check
    {
        $ready = Plugin::commerceIsReady();

        return new Check([
            'id' => 'commerce',
            'label' => Craft::t('coinpurse', 'Craft Commerce'),
            'status' => $ready ? Check::PASS : Check::FAIL,
            'detail' => $ready
                ? Craft::t('coinpurse', 'Installed and enabled.')
                : Craft::t('coinpurse', 'Not installed, or installed but disabled.'),
            'fix' => $ready ? '' : Craft::t('coinpurse', 'Coin Purse charges through Commerce’s own gateways and orders. Install Commerce first.'),
        ]);
    }

    private function httpsCheck(): Check
    {
        $url = UrlHelper::baseSiteUrl();
        $secure = str_starts_with(strtolower($url), 'https://');

        // A local development domain is exempt in spirit but not in fact: the wallets will not run
        // there either, and saying so plainly saves an hour of wondering.
        return new Check([
            'id' => 'https',
            'label' => Craft::t('coinpurse', 'HTTPS'),
            'status' => $secure ? Check::PASS : Check::FAIL,
            'detail' => $secure
                ? Craft::t('coinpurse', 'The site is served over HTTPS.')
                : Craft::t('coinpurse', 'The site’s base URL is {url}.', ['url' => $url]),
            'fix' => $secure ? '' : Craft::t('coinpurse', 'Apple Pay and Google Pay refuse to run on anything but HTTPS, and fail silently when they do. No button will ever appear here.'),
        ]);
    }

    private function gatewayCheck(): Check
    {
        $gateway = Plugin::getInstance()->getDrivers()->getGateway();

        if (!$gateway) {
            return new Check([
                'id' => 'gateway',
                'label' => Craft::t('coinpurse', 'Gateway'),
                'status' => Check::FAIL,
                'detail' => Craft::t('coinpurse', 'No gateway is selected.'),
                'fix' => Craft::t('coinpurse', 'Choose the Commerce gateway express payments should be charged through, in Coin Purse’s settings.'),
            ]);
        }

        $enabled = (bool)$gateway->getIsFrontendEnabled();

        return new Check([
            'id' => 'gateway',
            'label' => Craft::t('coinpurse', 'Gateway'),
            'status' => $enabled ? Check::PASS : Check::WARN,
            'detail' => Craft::t('coinpurse', '{name} ({class}).', [
                'name' => $gateway->name,
                'class' => $gateway::displayName(),
            ]),
            'fix' => $enabled ? '' : Craft::t('coinpurse', 'This gateway isn’t enabled for the front end, so Commerce will refuse the payment.'),
        ]);
    }

    private function driverCheck(): Check
    {
        $driver = Plugin::getInstance()->getDrivers()->resolve();

        return new Check([
            'id' => 'driver',
            'label' => Craft::t('coinpurse', 'Driver'),
            'status' => $driver ? Check::PASS : Check::FAIL,
            'detail' => $driver
                ? $driver::displayName()
                : Craft::t('coinpurse', 'No driver can charge the selected gateway.'),
            'fix' => $driver ? '' : Craft::t('coinpurse', 'Set the driver to “Automatic”, or pick one that supports this gateway.'),
        ]);
    }

    private function walletsCheck(): Check
    {
        $wallets = Plugin::getInstance()->getSettings()->getEnabledWallets();

        return new Check([
            'id' => 'wallets',
            'label' => Craft::t('coinpurse', 'Wallets'),
            'status' => $wallets ? Check::PASS : Check::FAIL,
            'detail' => $wallets
                ? implode(', ', $wallets)
                : Craft::t('coinpurse', 'None are switched on.'),
            'fix' => $wallets ? '' : Craft::t('coinpurse', 'Switch on at least one wallet in Coin Purse’s settings.'),
        ]);
    }

    /**
     * @return Check[]
     */
    private function driverProblemChecks(): array
    {
        $drivers = Plugin::getInstance()->getDrivers();
        $driver = $drivers->resolve();
        $gateway = $drivers->getGateway();

        if (!$driver || !$gateway) {
            return [];
        }

        $problems = $driver->getProblems($gateway);

        $checks = [];

        if (!$problems) {
            $checks[] = new Check([
                'id' => 'driver-config',
                'label' => Craft::t('coinpurse', 'Driver configuration'),
                'status' => Check::PASS,
                'detail' => Craft::t('coinpurse', 'Nothing missing.'),
            ]);
        }

        foreach ($problems as $i => $problem) {
            $checks[] = new Check([
                'id' => 'driver-config-' . $i,
                'label' => Craft::t('coinpurse', 'Driver configuration'),
                'status' => Check::FAIL,
                'detail' => $problem,
            ]);
        }

        // Naming the attribute the wallet token will be written to turns the raw driver's one
        // genuinely site-specific decision into something the merchant can see and check.
        if ($driver instanceof RawDriver) {
            try {
                $attribute = $driver->resolveTokenAttribute($gateway);
            } catch (\Throwable $e) {
                $attribute = null;
            }

            $checks[] = new Check([
                'id' => 'token-attribute',
                'label' => Craft::t('coinpurse', 'Wallet token field'),
                'status' => $attribute ? Check::PASS : Check::FAIL,
                'detail' => $attribute
                    ? Craft::t('coinpurse', 'The token will be written to `{attribute}` on this gateway’s payment form.', ['attribute' => $attribute])
                    : Craft::t('coinpurse', 'Coin Purse can’t tell which field takes the token.'),
                'fix' => $attribute ? '' : Craft::t('coinpurse', 'Name the field in Coin Purse’s settings. Your gateway plugin’s payment form model will tell you what it’s called.'),
            ]);
        }

        return $checks;
    }

    private function shippingCheck(): Check
    {
        $methods = Commerce::getInstance()->getShippingMethods()->getAllShippingMethods();
        $enabled = array_filter($methods->all(), static fn($m) => $m->getIsEnabled());

        return new Check([
            'id' => 'shipping',
            'label' => Craft::t('coinpurse', 'Shipping methods'),
            'status' => $enabled ? Check::PASS : Check::WARN,
            'detail' => Craft::t('coinpurse', '{count} enabled.', ['count' => count($enabled)]),
            'fix' => $enabled ? '' : Craft::t('coinpurse', 'A button that asks for shipping will open a sheet with no rates in it, and the customer can’t get past that. Either add a shipping method or render the button without `requestShipping`.'),
        ]);
    }

    private function domainAssociationCheck(): ?Check
    {
        $settings = Plugin::getInstance()->getSettings();

        // Only the raw driver needs Coin Purse to serve this file; on Stripe, Stripe hosts and
        // registers the domain itself.
        $driver = Plugin::getInstance()->getDrivers()->resolve();

        if (!$driver instanceof RawDriver || !$settings->enableApplePay) {
            return null;
        }

        $association = $settings->getApplePayDomainAssociation();

        return new Check([
            'id' => 'apple-domain',
            'label' => Craft::t('coinpurse', 'Apple Pay domain association'),
            'status' => $association ? Check::PASS : Check::FAIL,
            'detail' => $association
                ? Craft::t('coinpurse', 'Served at /{path}', ['path' => ApplePay::DOMAIN_ASSOCIATION_PATH])
                : Craft::t('coinpurse', 'No domain association file is configured.'),
            'fix' => $association ? '' : Craft::t('coinpurse', 'Download the file from your Apple developer account and paste its contents — or its path on disk — into Coin Purse’s settings.'),
        ]);
    }
}
