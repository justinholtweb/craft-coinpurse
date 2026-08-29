<?php

namespace justinholtweb\coinpurse\services;

use Craft;
use craft\base\Component;
use craft\commerce\elements\Order;
use craft\commerce\models\ShippingMethodOption;
use justinholtweb\coinpurse\events\QuoteEvent;
use justinholtweb\coinpurse\helpers\Money;
use justinholtweb\coinpurse\models\ExpressSession;
use justinholtweb\coinpurse\models\Quote;
use justinholtweb\coinpurse\models\QuoteLine;
use justinholtweb\coinpurse\models\ShippingOption;
use justinholtweb\coinpurse\Plugin;

/**
 * The only place in Coin Purse where money is added up.
 *
 * Invariant: the sheet's opening line items, the totals after an address change, the totals after
 * a shipping-rate change and the amount finally charged are all this one method's output, taken
 * from the order at that instant. Nothing else may compute a total, and no driver is given the
 * pieces to compute one of its own — they get a `Quote` or nothing.
 *
 * The reason for the strictness is that the failure mode is uniquely bad. A wallet sheet is a
 * system dialog the customer trusts more than the site; if it says $42.00 and the card is charged
 * $46.00, that is a chargeback, and quite possibly a complaint to Apple.
 */
class Quotes extends Component
{
    /**
     * @event QuoteEvent Raised after a quote is built, before any wallet sees it.
     */
    public const EVENT_AFTER_BUILD_QUOTE = 'afterBuildQuote';

    public function build(ExpressSession $session): Quote
    {
        $order = $session->getOrder();

        if (!$order) {
            throw new \RuntimeException('Cannot quote an express session with no order.');
        }

        $options = $session->getOptions();
        $settings = Plugin::getInstance()->getSettings();

        $order->recalculate();

        $currency = $order->getPaymentCurrency();

        $quote = new Quote([
            'currency' => $currency,
            'decimals' => (int)round(log10(Money::subunitFactor($currency))),
            'countryCode' => $settings->merchantCountryCode,
            'label' => $settings->getMerchantName(),
            'total' => Money::toMinor($order->getTotalPrice(), $currency),
            'lines' => $this->lines($order, $currency),
            'selectedShippingOption' => $order->shippingMethodHandle,
        ]);

        if ($options->needsShipping()) {
            $address = $order->getShippingAddress();

            $quote->needsShippingAddress = $address === null || !$address->countryCode;

            if (!$quote->needsShippingAddress) {
                $quote->shippingOptions = $this->shippingOptions($order, $currency);

                if (!$quote->shippingOptions) {
                    $quote->shippingError = Craft::t('coinpurse', 'We can’t ship to that address.');
                }
            }
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_BUILD_QUOTE)) {
            $event = new QuoteEvent([
                'quote' => $quote,
                'order' => $order,
                'session' => $session,
            ]);

            $this->trigger(self::EVENT_AFTER_BUILD_QUOTE, $event);

            // The total is not the handler's to change; re-assert it from the order so that a
            // well-meaning handler cannot introduce the one bug this class exists to prevent.
            $event->quote->total = Money::toMinor($order->getTotalPrice(), $currency);

            $quote = $event->quote;
        }

        return $quote;
    }

    /**
     * Whether an order is in a state a wallet can legitimately be asked to pay for.
     *
     * @return string|null The reason it is not, or null if it is fine.
     */
    public function unpayableReason(Order $order): ?string
    {
        if ($order->isCompleted) {
            return Craft::t('coinpurse', 'This order has already been placed.');
        }

        if (!$order->getLineItems()) {
            return Craft::t('coinpurse', 'There’s nothing in this order.');
        }

        if ($order->getTotalPrice() <= 0) {
            // A wallet cannot authorise a zero-value charge, and pretending otherwise produces an
            // opaque failure inside the sheet rather than an explanation on the page.
            return Craft::t('coinpurse', 'This order’s total is zero, so there’s nothing to pay.');
        }

        return null;
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @return QuoteLine[]
     */
    private function lines(Order $order, string $currency): array
    {
        $lines = [];

        foreach ($order->getLineItems() as $lineItem) {
            $lines[] = new QuoteLine([
                'type' => QuoteLine::TYPE_ITEM,
                'name' => $this->lineItemName($lineItem->getDescription(), (int)$lineItem->qty),
                'amount' => Money::toMinor($lineItem->getSubtotal(), $currency),
            ]);
        }

        // Adjustments are summarised rather than itemised. A wallet sheet is a phone-sized list;
        // eleven separate tax lines in it is worse for the customer than one that adds up.
        $discount = $order->getTotalDiscount();

        if ($discount != 0) {
            $lines[] = new QuoteLine([
                'type' => QuoteLine::TYPE_DISCOUNT,
                'name' => Craft::t('coinpurse', 'Discount'),
                'amount' => Money::toMinor($discount, $currency),
            ]);
        }

        $shipping = $order->getTotalShippingCost();

        if ($shipping != 0) {
            $lines[] = new QuoteLine([
                'type' => QuoteLine::TYPE_SHIPPING,
                'name' => Craft::t('coinpurse', 'Shipping'),
                'amount' => Money::toMinor($shipping, $currency),
            ]);
        }

        // Included tax is already inside the item prices; adding it again would show a total in the
        // sheet larger than the one the order will charge.
        $tax = $order->getTotalTax();

        if ($tax != 0) {
            $lines[] = new QuoteLine([
                'type' => QuoteLine::TYPE_TAX,
                'name' => Craft::t('coinpurse', 'Tax'),
                'amount' => Money::toMinor($tax, $currency),
            ]);
        }

        // Anything Commerce (or a third-party adjuster) added that is none of the above still has
        // to appear, or the lines will not add up to the total the customer is asked to authorise.
        $accounted = $order->getItemSubtotal() + $discount + $shipping + $tax;
        $remainder = $order->getTotalPrice() - $accounted;

        if (Money::toMinor(abs($remainder), $currency) > 0) {
            $lines[] = new QuoteLine([
                'type' => QuoteLine::TYPE_ADJUSTMENT,
                'name' => Craft::t('coinpurse', 'Adjustments'),
                'amount' => Money::toMinor($remainder, $currency),
            ]);
        }

        return $lines;
    }

    private function lineItemName(string $description, int $qty): string
    {
        return $qty > 1 ? "{$description} × {$qty}" : $description;
    }

    /**
     * @return ShippingOption[]
     */
    private function shippingOptions(Order $order, string $currency): array
    {
        $options = [];

        /** @var ShippingMethodOption $method */
        foreach ($order->getAvailableShippingMethodOptions() as $method) {
            if (!$method->matchesOrder) {
                continue;
            }

            $options[] = new ShippingOption([
                'id' => $method->getHandle(),
                'label' => $method->getName(),
                'detail' => '',
                'amount' => Money::toMinor($method->price, $currency),
            ]);
        }

        // Cheapest first: wallets pre-select the first option in the list, and pre-selecting the
        // most expensive courier because it happened to sort first is not a neutral default.
        usort($options, static fn(ShippingOption $a, ShippingOption $b) => $a->amount <=> $b->amount);

        return $options;
    }
}
