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
     */
    public function render(array $config = []): Markup
    {
        $view = Craft::$app->getView();

        if (!Plugin::getInstance()->isReady()) {
            return new Markup('', Craft::$app->charset);
        }

        $driver = Plugin::getInstance()->getDrivers()->resolve();
        $gateway = Plugin::getInstance()->getDrivers()->getGateway();

        if (!$driver || !$gateway) {
            return new Markup('', Craft::$app->charset);
        }

        $options = $this->normalise($config);

        if ($options->mode === ButtonOptions::MODE_ITEMS && !$options->items) {
            return new Markup('', Craft::$app->charset);
        }

        $id = 'coinpurse-' . StringHelper::randomString(10);

        $payload = $options->signablePayload();

        $settings = Plugin::getInstance()->getSettings();

        $buttonConfig = [
            'id' => $id,
            'driver' => $driver::handle(),
            'payload' => Signature::sign($payload),
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

        $view->registerAssetBundle(ExpressAsset::class);

        $html = Html::tag('div', '', [
            'id' => $id,
            'class' => 'coinpurse-button',
            'data' => ['coinpurse' => true],
            'style' => ['min-height' => $buttonConfig['style']['height'] . 'px'],
        ]);

        $view->registerJs(
            'window.CoinPurse && window.CoinPurse.mount(' . Json::encode($buttonConfig) . ');',
            View::POS_END
        );

        return new Markup($html, Craft::$app->charset);
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
