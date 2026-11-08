<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\View;
use justinholtweb\coinpurse\helpers\Signature;
use justinholtweb\coinpurse\models\ButtonOptions;
use justinholtweb\coinpurse\Plugin;
use justinholtweb\coinpurse\web\assets\express\ExpressAsset;
use Twig\Markup;

/**
 * Turning `{{ craft.coinpurse.button({...}) }}` into markup.
 *
 * The option names match `ether/web-payments` deliberately. A site migrating off an abandoned
 * plugin should be able to change the tag name and keep its templates; making them relearn an
 * option vocabulary for no reason would be a poor way to welcome them.
 */
class Buttons extends Component
{
    /**
     * Render a wallet button.
     *
     * Returns an empty string — not an error, not a placeholder — when the plugin cannot serve
     * one. A button that cannot work must not occupy space on a product page, and the merchant
     * finds out why on the diagnostics screen rather than in a customer's browser.
     *
     * With “Inject the button script” on, the runtime is registered as an asset bundle and the
     * button is mounted by an inline `CoinPurse.mount(…)`. With it off, nothing is registered: the
     * button's markup carries the same configuration in `data-coinpurse-config`, and a runtime the
     * site bundled itself mounts it from `CoinPurse.start()`. Both are built by `config()`.
     */
    public function render(array $config = []): Markup
    {
        try {
            $buttonConfig = $this->config($config);

            if ($buttonConfig === null) {
                return new Markup('', Craft::$app->charset);
            }

            $view = Craft::$app->getView();
            $id = $buttonConfig['id'];

            $attributes = [
                'id' => $id,
                'class' => 'coinpurse-button',
                'data' => ['coinpurse' => true],
                'style' => ['min-height' => $buttonConfig['style']['height'] . 'px'],
            ];

            if (!Plugin::getInstance()->getSettings()->injectRuntime) {
                $attributes['data-coinpurse-config'] = Json::encode($this->attributeConfig($buttonConfig));

                return new Markup(Html::tag('div', '', $attributes), Craft::$app->charset);
            }

            $view->registerAssetBundle(ExpressAsset::class);

            $view->registerJs(
                'window.CoinPurse && window.CoinPurse.mount(' . Json::encode($buttonConfig) . ');',
                View::POS_END
            );

            return new Markup(Html::tag('div', '', $attributes), Craft::$app->charset);
        } catch (\Throwable $e) {
            Craft::error('Coin Purse could not render a button: ' . $e->getMessage(), 'coinpurse');

            return new Markup('', Craft::$app->charset);
        }
    }

    /**
     * The browser-side configuration of one button, or null when no button can be served.
     *
     * The one place it is built: the injected `CoinPurse.mount(…)` call encodes it as it is, and
     * the `data-coinpurse-config` attribute a bundled runtime reads is `attributeConfig()` of it.
     *
     * Nothing in it is visitor-specific. The payload is signed over the items (or just "the
     * cart", which is resolved from the session when the sheet opens), site, gateway and an expiry;
     * amounts, the gateway's publishable key and the CSRF token are all fetched by the browser at
     * `express/start` and `users/session-info`, never written into the page.
     */
    public function config(array $config = []): ?array
    {
        if (!Plugin::getInstance()->isReady()) {
            return null;
        }

        $driver = Plugin::getInstance()->getDrivers()->resolve();
        $gateway = Plugin::getInstance()->getDrivers()->getGateway();

        if (!$driver || !$gateway) {
            return null;
        }

        $options = $this->normalise($config);

        if ($options->mode === ButtonOptions::MODE_ITEMS && !$options->items) {
            return null;
        }

        $settings = Plugin::getInstance()->getSettings();

        return [
            'id' => 'coinpurse-' . StringHelper::randomString(10),
            'driver' => $driver::handle(),
            'payload' => Signature::sign($options->signablePayload()),
            'endpoints' => $this->endpoints(),
            'style' => [
                'type' => $config['style']['type'] ?? $settings->buttonType,
                'theme' => $config['style']['theme'] ?? $settings->buttonTheme,
                'height' => (int)($config['style']['height'] ?? $settings->buttonHeight),
            ],
            'onComplete' => [
                'redirect' => $config['onComplete']['redirect'] ?? null,
                'js' => $config['onComplete']['js'] ?? null,
            ],
            'requestShipping' => $options->requestShipping,
            'shippingType' => $options->shippingType(),
            'requestDetails' => $options->normalisedDetails(),
            'variable' => $config['js'] ?? null,
        ];
    }

    /**
     * What goes into `data-coinpurse-config`: the injected configuration, minus code.
     *
     * An attribute is data. Sanitisers that let user content through commonly allow `data-*`, so
     * a runtime that executed JavaScript found in one would turn any such page into a script
     * gadget. `onComplete.js` is therefore never written into the attribute — a bundling site
     * passes a function to `CoinPurse.start({ onComplete })` or listens for `coinpurse:complete` —
     * and the runtime refuses it there anyway, and treats `variable` as a name, not a statement.
     */
    public function attributeConfig(array $buttonConfig): array
    {
        if (!empty($buttonConfig['onComplete']['js'])) {
            Craft::warning(
                'Coin Purse ignored onComplete.js on a button rendered for a bundled runtime. Pass a function to CoinPurse.start({ onComplete }) or listen for the coinpurse:complete event instead.',
                'coinpurse'
            );
        }

        unset($buttonConfig['onComplete']['js']);

        return $buttonConfig;
    }

    /**
     * Normalise the template's options into the signable shape.
     */
    public function normalise(array $config): ButtonOptions
    {
        $settings = Plugin::getInstance()->getSettings();

        $options = new ButtonOptions();

        $cart = $config['cart'] ?? null;

        if ($cart !== null) {
            $options->mode = ButtonOptions::MODE_CART;
        } else {
            $options->mode = ButtonOptions::MODE_ITEMS;
            $options->items = $this->normaliseItems($config['items'] ?? []);
        }

        $options->requestShipping = $this->normaliseShipping($config['requestShipping'] ?? false);
        $options->requestDetails = $this->normaliseDetails($config['requestDetails'] ?? null);
        $options->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $options->gatewayId = $settings->gatewayId;
        $options->expires = time() + ($settings->sessionLifetimeMinutes * 60);

        return $options;
    }

    /**
     * The action URLs the browser will call. Built once, here, so the JS never assembles a URL.
     */
    public function endpoints(): array
    {
        return [
            'start' => UrlHelper::actionUrl('coinpurse/express/start'),
            'address' => UrlHelper::actionUrl('coinpurse/express/address'),
            'shipping' => UrlHelper::actionUrl('coinpurse/express/shipping'),
            'pay' => UrlHelper::actionUrl('coinpurse/express/pay'),
            'complete' => UrlHelper::actionUrl('coinpurse/express/complete'),
            'cancel' => UrlHelper::actionUrl('coinpurse/express/cancel'),
            'validateMerchant' => UrlHelper::actionUrl('coinpurse/apple-pay/validate-merchant'),
            'sessionInfo' => UrlHelper::actionUrl('users/session-info'),
        ];
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @return array<int, array{id: int, qty: int, options: array, note: string}>
     */
    private function normaliseItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalised = [];

        foreach ($items as $item) {
            if (is_numeric($item)) {
                $item = ['id' => $item];
            }

            if (!is_array($item)) {
                continue;
            }

            $id = (int)($item['id'] ?? $item['purchasableId'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $itemOptions = $item['options'] ?? [];

            $normalised[] = [
                'id' => $id,
                'qty' => max(1, (int)($item['qty'] ?? $item['quantity'] ?? 1)),
                'options' => is_array($itemOptions) ? $itemOptions : [],
                'note' => (string)($item['note'] ?? ''),
            ];
        }

        return $normalised;
    }

    private function normaliseShipping(mixed $value): string|false
    {
        if ($value === true) {
            return 'shipping';
        }

        if (is_string($value) && in_array($value, ['shipping', 'delivery', 'pickup'], true)) {
            return $value;
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function normaliseDetails(mixed $value): array
    {
        $settings = Plugin::getInstance()->getSettings();

        $details = is_array($value) ? array_map('strval', $value) : [];

        // Commerce cannot complete an order without an email address, so asking the wallet for one
        // is not optional however the template is written.
        if ($settings->requireEmail) {
            $details[] = 'email';
        }

        if ($settings->requireName) {
            $details[] = 'name';
        }

        if ($settings->requirePhone) {
            $details[] = 'phone';
        }

        return array_values(array_intersect(array_unique($details), ['name', 'email', 'phone']));
    }
}
