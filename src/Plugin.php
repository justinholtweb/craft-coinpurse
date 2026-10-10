<?php

namespace justinholtweb\coinpurse;

use Craft;
use craft\base\conditions\BaseCondition;
use craft\base\Element;
use craft\base\Model;
use craft\commerce\elements\conditions\orders\DiscountOrderCondition;
use craft\commerce\elements\conditions\orders\GatewayOrderCondition;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\conditions\orders\ShippingMethodOrderCondition;
use craft\commerce\elements\conditions\orders\ShippingRuleOrderCondition;
use craft\commerce\elements\Order;
use craft\elements\db\ElementQuery;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\ElementIndexTableAttributeEvent;
use craft\events\PopulateElementsEvent;
use craft\events\RegisterConditionRulesEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\coinpurse\conditions\ExpressCheckoutConditionRule;
use justinholtweb\coinpurse\models\Settings;
use justinholtweb\coinpurse\services\ApplePay;
use justinholtweb\coinpurse\services\Buttons;
use justinholtweb\coinpurse\services\Checkout;
use justinholtweb\coinpurse\services\Diagnostics;
use justinholtweb\coinpurse\services\Drivers;
use justinholtweb\coinpurse\services\ExpressOrders;
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
 * @property-read ExpressOrders $expressOrders
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'coinpurse';

    /** The order index column's key. */
    public const TABLE_ATTRIBUTE = 'coinpurseWallet';

    public string $schemaVersion = '5.1.0';
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
                'expressOrders' => ['class' => ExpressOrders::class],
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
        $this->_registerOrderIndex();
        $this->_registerGarbageCollection();
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

    public function getExpressOrders(): ExpressOrders
    {
        return $this->get('expressOrders');
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

    /**
     * Commerce → Orders: an "Express checkout" column and condition rule.
     *
     * The rule is kept off discount, shipping and gateway conditions — see the rule's docblock.
     */
    private function _registerOrderIndex(): void
    {
        if (!class_exists(Order::class)) {
            return;
        }

        Event::on(
            OrderCondition::class,
            BaseCondition::EVENT_REGISTER_CONDITION_RULES,
            static function(RegisterConditionRulesEvent $event) {
                if (
                    $event->sender instanceof DiscountOrderCondition
                    || $event->sender instanceof ShippingMethodOrderCondition
                    || $event->sender instanceof ShippingRuleOrderCondition
                    || $event->sender instanceof GatewayOrderCondition
                ) {
                    return;
                }

                $event->conditionRules[] = ExpressCheckoutConditionRule::class;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            static function(RegisterElementTableAttributesEvent $event) {
                $event->tableAttributes[self::TABLE_ATTRIBUTE] = [
                    'label' => Craft::t('coinpurse', 'Express checkout'),
                ];
            }
        );

        // One query per index page for the column, rather than one per row.
        Event::on(
            Order::class,
            Element::EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE,
            function(ElementIndexTableAttributeEvent $event) {
                if ($event->attribute !== self::TABLE_ATTRIBUTE) {
                    return;
                }

                $event->query->on(
                    ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS,
                    function(PopulateElementsEvent $populated) {
                        $this->getExpressOrders()->prime(array_map(
                            static fn($element) => (int)$element->id,
                            array_filter($populated->elements, static fn($element) => $element instanceof Order)
                        ));
                    }
                );
                $event->handled = true;
            }
        );

        Event::on(
            Order::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $event) {
                if ($event->attribute !== self::TABLE_ATTRIBUTE) {
                    return;
                }

                /** @var Order $order */
                $order = $event->sender;
                $event->html = $this->getExpressOrders()->tableAttributeHtml($order);
                $event->handled = true;
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

    /**
     * Housekeeping that should not depend on a cron job somebody forgot to add.
     *
     * The console commands are still the reliable way — Craft's GC runs on a small fraction of
     * requests, or with `php craft gc` — but this means an unscheduled install stops accumulating
     * abandoned sessions and the detached orders behind replayed buy-now buttons.
     */
    private function _registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, [$this, 'collectGarbage']);
    }

    /**
     * @internal Craft's GC handler; public only so it can be registered and tested by name.
     */
    public function collectGarbage(): void
    {
        if (!self::commerceIsReady()) {
            return;
        }

        try {
            $this->getSessions()->reapAbandoned();
            $this->getSessions()->purge();
        } catch (\Throwable $e) {
            Craft::warning('Coin Purse could not clean up express sessions: ' . $e->getMessage(), 'coinpurse');
        }
    }
}
