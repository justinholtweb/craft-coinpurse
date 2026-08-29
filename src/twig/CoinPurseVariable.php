<?php

namespace justinholtweb\coinpurse\twig;

use craft\commerce\elements\Order;
use justinholtweb\coinpurse\models\Check;
use justinholtweb\coinpurse\Plugin;
use Twig\Markup;
use yii\base\Behavior;

/**
 * `craft.coinpurse.*`, and — for sites migrating off `ether/web-payments` — `craft.webPayments.*`.
 */
class CoinPurseVariable extends Behavior
{
    /**
     * Render a wallet button.
     *
     * ```twig
     * {{ craft.coinpurse.button({
     *     items: [{ id: variant.id, qty: 1 }],
     *     requestShipping: 'delivery',
     *     onComplete: { redirect: '/thanks?number={number}' },
     * }) }}
     * ```
     *
     * Returns an empty string when no button can be served, so it is always safe to call.
     */
    public function button(array $options = []): Markup
    {
        return Plugin::getInstance()->getButtons()->render($options);
    }

    /**
     * Whether a button rendered right now would actually appear.
     *
     * Useful for hiding a "or pay with" divider that would otherwise sit above nothing. Note that
     * this cannot know whether the *customer's* device has a wallet — only the server side of the
     * question is answerable here.
     */
    public function isAvailable(): bool
    {
        return Plugin::getInstance()->isReady();
    }

    /**
     * The driver handle that will be used, or null.
     */
    public function driver(): ?string
    {
        $driver = Plugin::getInstance()->getDrivers()->resolve();

        return $driver ? $driver::handle() : null;
    }

    /**
     * @return Check[]
     */
    public function diagnostics(): array
    {
        return Plugin::getInstance()->getDiagnostics()->run();
    }

    /**
     * Whether a given order was placed through express checkout.
     */
    public function wasExpress(Order $order): bool
    {
        if (!$order->id) {
            return false;
        }

        return Plugin::getInstance()->getSessions()->wasExpressOrder($order->id);
    }
}
