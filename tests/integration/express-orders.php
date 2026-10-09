<?php
/**
 * Coin Purse — express-order record, order index column and "Express checkout" condition rule.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     ddev exec php /var/www/craft-coinpurse/tests/integration/express-orders.php
 *
 * Runs real express purchases through Commerce's Dummy gateway, applies the condition rule to a
 * real order query, renders the real Commerce → Orders table with the column switched on, and posts
 * to `express/pay` over HTTP to check the wallet the browser reports is sanitised on the way in.
 * Fixture products, orders, sessions and settings are restored in a `finally`.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\base\Element;
use craft\commerce\elements\conditions\orders\DiscountOrderCondition;
use craft\commerce\elements\conditions\orders\OrderCondition;
use craft\commerce\elements\conditions\orders\ShippingMethodOrderCondition;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\gateways\Dummy;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\events\ElementIndexTableAttributeEvent;
use justinholtweb\coinpurse\conditions\ExpressCheckoutConditionRule;
use justinholtweb\coinpurse\db\Table;
use justinholtweb\coinpurse\drivers\RawDriver;
use justinholtweb\coinpurse\models\ButtonOptions;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Settings;
use justinholtweb\coinpurse\models\WalletAddress;
use justinholtweb\coinpurse\models\WalletPayer;
use justinholtweb\coinpurse\Plugin;
use justinholtweb\coinpurse\services\ExpressOrders;
use justinholtweb\coinpurse\twig\CoinPurseVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();
$commerce = Commerce::getInstance();
$expressOrders = $plugin->getExpressOrders();
$suffix = substr(md5((string)microtime(true)), 0, 6);

$createdProducts = [];
$createdOrders = [];
$createdSessionIds = [];
$createdGatewayId = null;
$originalSettings = $plugin->getSettings()->toArray();

// Same two sibling-plugin workarounds as checks.php; see the notes there.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
    echo "  ! detached craft-penny's broken beforeSaveElement handler for this run\n";
}

if (Craft::$app->getPlugins()->isPluginEnabled('lyfe')) {
    yii\base\Event::off(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER);
    echo "  ! detached craft-lyfe's afterCompleteOrder handler for this run\n";
}

function applySettings(array $values): void
{
    global $plugin;

    Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    Craft::$app->getProjectConfig()->saveModifiedConfigData();
}

function makeProduct(string $sku, float $price): Product
{
    global $createdProducts;

    $type = Commerce::getInstance()->getProductTypes()->getAllProductTypes()[0];

    $product = new Product();
    $product->typeId = $type->id;
    $product->title = "Coin Purse fixture $sku";
    $product->enabled = true;

    $variant = new Variant();
    $variant->sku = $sku;
    $variant->basePrice = $price;
    $variant->weight = 1;
    $variant->isDefault = true;

    $product->setVariants([$variant]);

    if (!Craft::$app->getElements()->saveElement($product)) {
        throw new RuntimeException('Could not save fixture product: ' . json_encode($product->getErrors()));
    }

    $createdProducts[] = $product;

    return $product;
}

function openSession(Variant $variant): ExpressSession
{
    global $plugin, $createdOrders, $createdSessionIds;

    $session = $plugin->getSessions()->open(new ButtonOptions([
        'mode' => ButtonOptions::MODE_ITEMS,
        'items' => [['id' => $variant->id, 'qty' => 1]],
        'requestShipping' => false,
        'requestDetails' => ['email'],
        'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
        'gatewayId' => $plugin->getSettings()->gatewayId,
        'expires' => time() + 600,
    ]), 'raw');

    $createdOrders[] = $session->getOrder();
    $createdSessionIds[] = $session->id;

    return $session;
}

/**
 * A whole express purchase through the Dummy gateway, with the wallet the browser would report.
 */
function buyWith(Variant $variant, Dummy $gateway, mixed $wallet): Order
{
    global $plugin;

    $session = openSession($variant);

    $plugin->getExpressOrders()->rememberWallet($session, $wallet);

    $driver = new RawDriver();

    $plugin->getCheckout()->prepare(
        $session,
        $gateway,
        new WalletPayer(['name' => 'Jenny Rosen', 'email' => 'coinpurse-wallet@example.com']),
        null,
        new WalletAddress([
            'countryCode' => 'US',
            'addressLine1' => '1 Test Street',
            'locality' => 'Charlotte',
            'administrativeArea' => 'NC',
            'postalCode' => '28202',
        ]),
        null,
        fn(Order $o) => $driver->createPaymentForm($session, $gateway, ['token' => 'tok_test']),
        null,
    );

    $result = $plugin->getCheckout()->complete($session);

    return $result['order'];
}

/** @return int[] */
function ruleIds(string $value, array $ids, string $conditionClass = OrderCondition::class): array
{
    /** @var OrderCondition $condition */
    $condition = Craft::$app->getConditions()->createCondition(['class' => $conditionClass]);
    $rule = Craft::$app->getConditions()->createConditionRule([
        'class' => ExpressCheckoutConditionRule::class,
        'value' => $value,
    ]);
    $condition->addConditionRule($rule);

    $query = Order::find()->id($ids)->status(null)->isCompleted(null);
    $condition->modifyQuery($query);

    $found = array_map('intval', $query->ids());
    sort($found);

    return $found;
}

try {
    // -----------------------------------------------------------------
    section('Schema');

    check('the express-orders table exists', fn() => Craft::$app->getDb()->tableExists(Table::EXPRESS_ORDERS) ?: 'missing');

    check('express sessions have a wallet column', fn() => Craft::$app->getDb()->columnExists(Table::SESSIONS, 'wallet') ?: 'missing');

    check('the installed schema version matches the plugin', function() use ($plugin) {
        $installed = Craft::$app->getPlugins()->getStoredPluginInfo('coinpurse')['schemaVersion'] ?? null;

        return $installed === $plugin->schemaVersion ?: "installed $installed, plugin {$plugin->schemaVersion}";
    });

    // -----------------------------------------------------------------
    section('Wallet normalisation');

    check('Coin Purse’s own handles are kept', fn() => $expressOrders->normalise('googlePay') === Settings::WALLET_GOOGLE_PAY ?: 'changed');

    check('Stripe’s expressPaymentType values are mapped', function() use ($expressOrders) {
        return $expressOrders->normalise('apple_pay') === Settings::WALLET_APPLE_PAY
            && $expressOrders->normalise('google_pay') === Settings::WALLET_GOOGLE_PAY
            && $expressOrders->normalise('amazon_pay') === Settings::WALLET_AMAZON_PAY
            ?: 'not mapped';
    });

    check('anything else is dropped rather than stored', function() use ($expressOrders) {
        foreach (['<script>alert(1)</script>', 'APPLEPAY', '', ['applePay'], 42, null, ExpressOrders::UNKNOWN] as $hostile) {
            if ($expressOrders->normalise($hostile) !== null) {
                return 'kept ' . var_export($hostile, true);
            }
        }

        return true;
    });

    // -----------------------------------------------------------------
    section('Recording express orders');

    $productA = makeProduct("CPW-A-$suffix", 12.00);
    $variantA = $productA->getDefaultVariant();

    $gateway = null;

    foreach ($commerce->getGateways()->getAllGateways() as $existing) {
        if ($existing instanceof Dummy) {
            $gateway = $existing;
            break;
        }
    }

    if (!$gateway) {
        $gateway = new Dummy();
        $gateway->name = "Coin Purse wallet test gateway $suffix";
        $gateway->handle = "coinpurseWalletTest$suffix";
        $gateway->paymentType = 'purchase';
        $gateway->setIsFrontendEnabled(true);

        if (!$commerce->getGateways()->saveGateway($gateway)) {
            throw new RuntimeException('Could not save the fixture gateway: ' . json_encode($gateway->getErrors()));
        }

        Craft::$app->getProjectConfig()->saveModifiedConfigData();
        $createdGatewayId = $gateway->id;
    }

    applySettings(array_merge($originalSettings, [
        'gatewayId' => $gateway->id,
        'driver' => 'raw',
        'enableApplePay' => true,
        'enableGooglePay' => true,
        'tokenTargetAttribute' => null,
        'tokenEncoding' => Settings::ENCODING_RAW,
    ]));

    $googleOrder = buyWith($variantA, $gateway, 'googlePay');
    $appleOrder = buyWith($variantA, $gateway, 'apple_pay');
    $unknownOrder = buyWith($variantA, $gateway, null);

    // An ordinary order, never near a wallet sheet.
    $plainOrder = new Order();
    $plainOrder->number = $commerce->getCarts()->generateCartNumber();
    $plainOrder->orderSiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $plainOrder->storeId = $commerce->getStores()->getPrimaryStore()->id;
    Craft::$app->getElements()->saveElement($plainOrder, false);
    $createdOrders[] = $plainOrder;

    $ids = [(int)$googleOrder->id, (int)$appleOrder->id, (int)$unknownOrder->id, (int)$plainOrder->id];
    $expressOrders->reset();

    check('a completed express order is recorded with the wallet the browser reported', function() use ($expressOrders, $googleOrder) {
        return $googleOrder->isCompleted && $expressOrders->walletFor((int)$googleOrder->id) === Settings::WALLET_GOOGLE_PAY
            ?: 'got ' . var_export($expressOrders->walletFor((int)$googleOrder->id), true);
    });

    check('a Stripe wallet type is recorded under Coin Purse’s handle', fn() => $expressOrders->walletFor((int)$appleOrder->id) === Settings::WALLET_APPLE_PAY
        ?: 'got ' . var_export($expressOrders->walletFor((int)$appleOrder->id), true));

    check('an express order with no reported wallet is still express, wallet unknown', fn() => $expressOrders->walletFor((int)$unknownOrder->id) === ExpressOrders::UNKNOWN
        ?: 'got ' . var_export($expressOrders->walletFor((int)$unknownOrder->id), true));

    check('an ordinary order is not express', fn() => $expressOrders->walletFor((int)$plainOrder->id) === null && !$expressOrders->isExpress((int)$plainOrder->id) ?: 'it was');

    check('completing again does not add a second record', function() use ($plugin, $googleOrder) {
        $session = $plugin->getSessions()->getByUid(
            (string)(new Query())->select(['uid'])->from(Table::SESSIONS)->where(['orderId' => $googleOrder->id])->scalar()
        );
        $plugin->getCheckout()->complete($session);

        $count = (new Query())->from(Table::EXPRESS_ORDERS)->where(['orderId' => $googleOrder->id])->count();

        return (int)$count === 1 ?: "$count rows";
    });

    check('the record outlives the session row', function() use ($plugin, $googleOrder, $expressOrders) {
        Craft::$app->getDb()->createCommand()->delete(Table::SESSIONS, ['orderId' => $googleOrder->id])->execute();
        $expressOrders->reset();

        return $plugin->getSessions()->wasExpressOrder((int)$googleOrder->id)
            && $expressOrders->walletFor((int)$googleOrder->id) === Settings::WALLET_GOOGLE_PAY
            ?: 'lost with the session';
    });

    check('craft.coinpurse.wallet / walletLabel / wasExpress answer from the record', function() use ($googleOrder, $plainOrder) {
        $variable = new CoinPurseVariable();

        return $variable->wallet($googleOrder) === 'googlePay'
            && $variable->walletLabel($googleOrder) === 'Google Pay'
            && $variable->wasExpress($googleOrder)
            && $variable->wallet($plainOrder) === null
            && $variable->walletLabel($plainOrder) === null
            && !$variable->wasExpress($plainOrder)
            ?: 'wrong answer';
    });

    // -----------------------------------------------------------------
    section('Condition rule');

    check('the rule is offered on order conditions', function() {
        $condition = Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class]);

        return array_key_exists(ExpressCheckoutConditionRule::class, $condition->getSelectableConditionRules()) ?: 'not offered';
    });

    check('the rule is not offered on discount or shipping-method conditions', function() use ($commerce) {
        $storeId = $commerce->getStores()->getPrimaryStore()->id;

        foreach ([DiscountOrderCondition::class, ShippingMethodOrderCondition::class] as $class) {
            $condition = Craft::$app->getConditions()->createCondition(['class' => $class, 'storeId' => $storeId]);

            if (array_key_exists(ExpressCheckoutConditionRule::class, $condition->getSelectableConditionRules())) {
                return "offered on $class";
            }
        }

        return true;
    });

    check('“Any wallet” narrows an order query to express orders', function() use ($ids, $googleOrder, $appleOrder, $unknownOrder) {
        $expected = [(int)$googleOrder->id, (int)$appleOrder->id, (int)$unknownOrder->id];
        sort($expected);

        return ruleIds(ExpressOrders::ANY, $ids) === $expected ?: 'got ' . json_encode(ruleIds(ExpressOrders::ANY, $ids));
    });

    check('a specific wallet narrows to that wallet', function() use ($ids, $googleOrder, $appleOrder) {
        return ruleIds(Settings::WALLET_GOOGLE_PAY, $ids) === [(int)$googleOrder->id]
            && ruleIds(Settings::WALLET_APPLE_PAY, $ids) === [(int)$appleOrder->id]
            && ruleIds(Settings::WALLET_KLARNA, $ids) === []
            ?: 'got google ' . json_encode(ruleIds(Settings::WALLET_GOOGLE_PAY, $ids)) . ' apple ' . json_encode(ruleIds(Settings::WALLET_APPLE_PAY, $ids));
    });

    check('“Not express checkout” narrows to ordinary orders', fn() => ruleIds(ExpressOrders::NONE, $ids) === [(int)$plainOrder->id]
        ?: 'got ' . json_encode(ruleIds(ExpressOrders::NONE, $ids)));

    check('matchElement agrees with the query', function() use ($googleOrder, $plainOrder) {
        $rule = static fn(string $value) => Craft::$app->getConditions()->createConditionRule([
            'class' => ExpressCheckoutConditionRule::class,
            'value' => $value,
            'condition' => Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class]),
        ]);

        return $rule(ExpressOrders::ANY)->matchElement($googleOrder)
            && $rule(Settings::WALLET_GOOGLE_PAY)->matchElement($googleOrder)
            && !$rule(Settings::WALLET_APPLE_PAY)->matchElement($googleOrder)
            && !$rule(ExpressOrders::ANY)->matchElement($plainOrder)
            && $rule(ExpressOrders::NONE)->matchElement($plainOrder)
            ?: 'disagreed';
    });

    check('the rule survives a config round trip and rejects unknown values', function() {
        $rule = Craft::$app->getConditions()->createConditionRule([
            'class' => ExpressCheckoutConditionRule::class,
            'value' => Settings::WALLET_APPLE_PAY,
            'condition' => Craft::$app->getConditions()->createCondition(['class' => OrderCondition::class]),
        ]);
        $copy = Craft::$app->getConditions()->createConditionRule($rule->getConfig() + ['condition' => $rule->getCondition()]);

        $bad = Craft::$app->getConditions()->createConditionRule([
            'class' => ExpressCheckoutConditionRule::class,
            'value' => '<script>',
            'condition' => $rule->getCondition(),
        ]);

        return $copy->value === Settings::WALLET_APPLE_PAY && $rule->validate(['value']) && !$bad->validate(['value'])
            ?: 'round trip or validation failed';
    });

    // -----------------------------------------------------------------
    section('Order index column');

    check('the column is offered on Commerce → Orders', function() {
        $attributes = Craft::$app->getElementSources()->getAvailableTableAttributes(Order::class);

        return array_key_exists(Plugin::TABLE_ATTRIBUTE, $attributes) ?: 'not offered';
    });

    check('the cell names the wallet, and is empty for an ordinary order', function() use ($googleOrder, $unknownOrder, $plainOrder, $expressOrders) {
        $expressOrders->reset();

        $google = $googleOrder->getAttributeHtml(Plugin::TABLE_ATTRIBUTE);
        $unknown = $unknownOrder->getAttributeHtml(Plugin::TABLE_ATTRIBUTE);
        $plain = $plainOrder->getAttributeHtml(Plugin::TABLE_ATTRIBUTE);

        return str_contains($google, 'Google Pay') && str_contains($unknown, 'Express checkout') && $plain === ''
            ?: "google=$google unknown=$unknown plain=$plain";
    });

    check('a page of orders loads its wallets in one query', function() use ($ids, $expressOrders) {
        $expressOrders->reset();

        $query = Order::find()->id($ids)->status(null)->isCompleted(null);
        $event = new ElementIndexTableAttributeEvent(['query' => $query, 'attribute' => Plugin::TABLE_ATTRIBUTE]);
        yii\base\Event::trigger(Order::class, Element::EVENT_PREP_QUERY_FOR_TABLE_ATTRIBUTE, $event);
        $orders = $query->all();

        $cache = (new ReflectionProperty(ExpressOrders::class, '_wallets'))->getValue($expressOrders);

        $loaded = array_keys($cache);
        sort($loaded);
        $want = $ids;
        sort($want);

        return $event->handled && $loaded === $want && count($orders) === 4 ?: 'primed ' . json_encode($loaded);
    });

    check('the real Commerce → Orders index renders the column (live CP request)', function() use ($ids) {
        $http = Craft::createGuzzleClient(['base_uri' => 'http://localhost/', 'http_errors' => false, 'cookies' => true, 'timeout' => 60]);
        $token = static fn() => json_decode((string)$http->get('index.php?p=admin/actions/users/session-info', [
            'headers' => ['Accept' => 'application/json'],
        ])->getBody(), true)['csrfTokenValue'] ?? '';

        $http->post('index.php?p=admin/actions/users/login', [
            'headers' => ['Accept' => 'application/json'],
            'form_params' => ['loginName' => 'admin', 'password' => 'claudepassword', 'CRAFT_CSRF_TOKEN' => $token()],
        ]);

        $response = $http->post('index.php?p=admin/actions/element-indexes/get-elements', [
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token()],
            'json' => [
                'elementType' => Order::class,
                'source' => '*',
                'context' => 'index',
                'viewState' => ['mode' => 'table', 'static' => false, 'tableColumns' => [Plugin::TABLE_ATTRIBUTE]],
                'criteria' => ['id' => $ids, 'status' => null, 'isCompleted' => null],
            ],
        ]);

        $html = json_decode((string)$response->getBody(), true)['html'] ?? '';

        return str_contains($html, 'data-wallet="googlePay"') && str_contains($html, 'data-wallet="applePay"')
            ?: 'status ' . $response->getStatusCode() . ': ' . substr(strip_tags((string)$response->getBody()), 0, 300);
    });

    // -----------------------------------------------------------------
    section('express/pay (live HTTP)');

    $client = Craft::createGuzzleClient([
        'base_uri' => 'http://localhost/',
        'timeout' => 30,
        'http_errors' => false,
        'cookies' => true,
    ]);

    $postPay = static function(ExpressSession $session, mixed $wallet) use ($client) {
        $token = json_decode((string)$client->get('index.php?p=actions/users/session-info', [
            'headers' => ['Accept' => 'application/json'],
        ])->getBody(), true)['csrfTokenValue'] ?? '';

        // An empty payment token: the charge is refused after the wallet has been noted, so nothing
        // is completed in the web process (where a sibling plugin breaks order completion).
        return $client->post('index.php?p=actions/coinpurse/express/pay', [
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => (string)$token],
            'form_params' => [
                'session' => $session->uid,
                'wallet' => $wallet,
                'payer' => ['email' => 'coinpurse-http@example.com'],
                'billingAddress' => ['source' => 'googlePay', 'address' => ['countryCode' => 'US', 'postalCode' => '28202']],
                'payment' => ['token' => ''],
            ],
        ]);
    };

    $walletOf = static fn(ExpressSession $s) => (new Query())->select(['wallet'])->from(Table::SESSIONS)->where(['id' => $s->id])->scalar();

    check('the wallet the browser reports reaches the session', function() use ($variantA, $postPay, $walletOf) {
        $session = openSession($variantA);
        $response = $postPay($session, 'google_pay');

        return $walletOf($session) === Settings::WALLET_GOOGLE_PAY
            ?: 'status ' . $response->getStatusCode() . ', wallet ' . var_export($walletOf($session), true) . ', body ' . substr((string)$response->getBody(), 0, 200);
    });

    check('a hostile wallet value is not stored', function() use ($variantA, $postPay, $walletOf) {
        $session = openSession($variantA);
        $response = $postPay($session, '<img src=x onerror=alert(1)>');

        return $walletOf($session) === null
            ?: 'stored ' . var_export($walletOf($session), true) . ' (status ' . $response->getStatusCode() . ')';
    });

    check('express/pay still refuses a request without a CSRF token', function() use ($variantA, $walletOf) {
        $session = openSession($variantA);
        $response = Craft::createGuzzleClient(['base_uri' => 'http://localhost/', 'http_errors' => false])
            ->post('index.php?p=actions/coinpurse/express/pay', [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => ['session' => $session->uid, 'wallet' => 'applePay'],
            ]);

        return $response->getStatusCode() === 400 && $walletOf($session) === null
            ?: 'status ' . $response->getStatusCode() . ', wallet ' . var_export($walletOf($session), true);
    });
} finally {
    section('Cleanup');

    $elements = Craft::$app->getElements();

    if ($createdSessionIds) {
        Craft::$app->getDb()->createCommand()->delete(Table::SESSIONS, ['id' => array_filter($createdSessionIds)])->execute();
    }

    foreach (array_reverse($createdOrders) as $fixtureOrder) {
        try {
            if ($fixtureOrder && $fixtureOrder->id) {
                $elements->deleteElement($fixtureOrder, true);
            }
        } catch (Throwable $e) {
            echo "  ! could not delete order {$fixtureOrder->id}: {$e->getMessage()}\n";
        }
    }

    foreach ($createdProducts as $fixtureProduct) {
        try {
            $elements->deleteElement($fixtureProduct, true);
        } catch (Throwable $e) {
            echo "  ! could not delete product {$fixtureProduct->id}: {$e->getMessage()}\n";
        }
    }

    if ($createdGatewayId) {
        try {
            Commerce::getInstance()->getGateways()->archiveGatewayById($createdGatewayId);
            Craft::$app->getProjectConfig()->saveModifiedConfigData();
        } catch (Throwable $e) {
            echo "  ! could not archive the fixture gateway: {$e->getMessage()}\n";
        }
    }

    try {
        applySettings($originalSettings);
    } catch (Throwable $e) {
        echo "  ! could not restore settings: {$e->getMessage()}\n";
    }

    $orphans = (int)(new Query())->from(Table::EXPRESS_ORDERS)->where(['orderId' => array_map(fn($o) => (int)$o?->id, $createdOrders)])->count();
    echo $orphans === 0 ? "  ✓ fixtures removed, settings restored\n" : "  ! $orphans express-order rows left behind\n";

    echo "\n" . str_repeat('-', 60) . "\n";
    echo "  $passed passed, $failed failed\n";
    echo str_repeat('-', 60) . "\n";
}

exit($failed > 0 ? 1 : 0);
