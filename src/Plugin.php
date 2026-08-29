<?php

namespace justinholtweb\coinpurse;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\coinpurse\models\Settings;
use justinholtweb\coinpurse\services\ApplePay;
use justinholtweb\coinpurse\services\Buttons;
use justinholtweb\coinpurse\services\Checkout;
use justinholtweb\coinpurse\services\Diagnostics;
use justinholtweb\coinpurse\services\Drivers;
use justinholtweb\coinpurse\services\Log;
use justinholtweb\coinpurse\services\Quotes;
use justinholtweb\coinpurse\services\Sessions;
use justinholtweb\coinpurse\twig\CoinPurseVariable;
use yii\base\Event;

/**
 * Coin Purse — Apple Pay and Google Pay express checkout for Craft Commerce.
 *
 * @property-read Buttons $buttons
 * @property-read Sessions $sessions
 * @property-read Quotes $quotes
 * @property-read Checkout $checkout
 * @property-read Drivers $drivers
 * @property-read ApplePay $applePay
 * @property-read Diagnostics $diagnostics
 * @property-read Log $log
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'coinpurse';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'buttons' => ['class' => Buttons::class],
                'sessions' => ['class' => Sessions::class],
                'quotes' => ['class' => Quotes::class],
                'checkout' => ['class' => Checkout::class],
                'drivers' => ['class' => Drivers::class],
                'applePay' => ['class' => ApplePay::class],
                'diagnostics' => ['class' => Diagnostics::class],
                'log' => ['class' => Log::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerCpRoutes();
        $this->_registerSiteRoutes();
    }

    /**
     * Whether Commerce is present and enabled.
     *
     * Coin Purse can be installed while Commerce is disabled or mid-upgrade, and every interesting
     * thing it does touches an order, so this is checked before any of it runs.
     */
    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether a button could actually be rendered right now.
     */
    public function isReady(): bool
    {
        if (!self::commerceIsReady()) {
            return false;
        }

        return $this->getDrivers()->resolve() !== null
            && $this->getSettings()->getEnabledWallets() !== [];
    }

    public function getButtons(): Buttons
    {
        return $this->get('buttons');
    }

    public function getSessions(): Sessions
    {
        return $this->get('sessions');
    }

    public function getQuotes(): Quotes
    {
        return $this->get('quotes');
    }

    public function getCheckout(): Checkout
    {
        return $this->get('checkout');
    }

    public function getDrivers(): Drivers
    {
        return $this->get('drivers');
    }

    public function getApplePay(): ApplePay
    {
        return $this->get('applePay');
    }

    public function getDiagnostics(): Diagnostics
    {
        return $this->get('diagnostics');
    }

    public function getLog(): Log
    {
        return $this->get('log');
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(
            \craft\helpers\UrlHelper::cpUrl('coinpurse/settings')
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if (!$item) {
            return null;
        }

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission('coinpurse-manage')) {
            $subnav['diagnostics'] = [
                'label' => Craft::t('coinpurse', 'Diagnostics'),
                'url' => 'coinpurse/diagnostics',
            ];
        }

        if ($user->checkPermission('coinpurse-viewLog')) {
            $subnav['log'] = [
                'label' => Craft::t('coinpurse', 'Log'),
                'url' => 'coinpurse/log',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subnav['settings'] = [
                'label' => Craft::t('coinpurse', 'Settings'),
                'url' => 'coinpurse/settings',
            ];
        }

        // A nav item with nothing under it is a dead end; hide the section rather than show one.
        if (!$subnav) {
            return null;
        }

        $item['subnav'] = $subnav;

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // ---------------------------------------------------------------- Registration

    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;

                $variable->set('coinpurse', CoinPurseVariable::class);

                // Templates written against `ether/web-payments` keep working. Skipped if that
                // plugin is actually installed, because taking its variable out from under it
                // would be a rude way to introduce ourselves.
                if (
                    $this->getSettings()->registerWebPaymentsAlias
                    && !Craft::$app->getPlugins()->isPluginInstalled('web-payments')
                ) {
                    $variable->set('webPayments', CoinPurseVariable::class);
                }
            }
        );
    }

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('coinpurse', 'Coin Purse'),
                    'permissions' => [
                        'coinpurse-manage' => [
                            'label' => Craft::t('coinpurse', 'Manage express checkout'),
                        ],
                        'coinpurse-viewLog' => [
                            'label' => Craft::t('coinpurse', 'View the express checkout log'),
                        ],
                    ],
                ];
            }
        );
    }

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                $event->rules['coinpurse'] = 'coinpurse/diagnostics/index';
                $event->rules['coinpurse/diagnostics'] = 'coinpurse/diagnostics/index';
                $event->rules['coinpurse/settings'] = 'coinpurse/settings/index';
                $event->rules['coinpurse/log'] = 'coinpurse/log/index';
                $event->rules['coinpurse/log/<id:\d+>'] = 'coinpurse/log/detail';
            }
        );
    }

    private function _registerSiteRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            static function(RegisterUrlRulesEvent $event) {
                // Apple fetches this over plain HTTP(S) with no session and no headers of ours.
                // It has to be a real route rather than a file the merchant is told to drop in
                // `web/`, because plenty of Craft sites have no writable path there.
                $event->rules[ApplePay::DOMAIN_ASSOCIATION_PATH] = 'coinpurse/apple-pay/domain-association';
            }
        );
    }
}
