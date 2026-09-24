---
title: Usage
slug: usage
order: 30
summary: The Twig tag and its options, buy-now versus cart buttons, the JavaScript handle, the console commands and the events.
---

## The button

```twig
{{ craft.coinpurse.button({
    items: [{ id: product.defaultVariant.id, qty: 1 }],
    requestShipping: 'delivery',
    requestDetails: ['name', 'email', 'phone'],
    onComplete: { redirect: '/thanks?number={number}' },
    style: { type: 'buy', theme: 'dark', height: 48 },
}) }}
```

| Option | Type | What it does |
| --- | --- | --- |
| `items` | array | What to buy. Each entry is `{ id, qty, options, note }`, or just a purchasable ID. |
| `cart` | Order | Buy the customer's existing cart instead of `items`. |
| `requestShipping` | bool or string | `false`, `true`, `'shipping'`, `'delivery'` or `'pickup'`. Changes the sheet's wording, and whether an address is collected at all. |
| `requestDetails` | array | Any of `name`, `email`, `phone`. Email is always collected. |
| `onComplete.redirect` | string | Where to send the customer afterwards. `{number}` becomes the order number. |
| `onComplete.js` | string | JavaScript to run afterwards. `cwp.number` and `cwp.email` are in scope. |
| `js` | string | A variable to assign the button to, e.g. `'window.payButton'`. |
| `style` | hash | `{ type, theme, height }`, overriding the defaults from the settings. |

The tag returns an empty string whenever no button can be served: no gateway, no HTTPS, nothing
purchasable. It is always safe to call, and it never renders a broken placeholder.

## Buy now, or check out the cart

**`items` mode** is a buy-now button. It builds a detached order with its own number that never
enters the session, so a one-tap purchase on a product page cannot disturb a cart the customer has
been filling for twenty minutes.

```twig
{{ craft.coinpurse.button({ items: [variant.id] }) }}
```

**`cart` mode** checks out the real cart, so coupon codes, gift notes and custom fields survive.

```twig
{{ craft.coinpurse.button({ cart: craft.commerce.carts.cart }) }}
```

### The cart and abandoned sheets

To quote shipping, a wallet sheet has to write an address onto the order. Customers dismiss sheets
all the time. Before a cart-mode sheet opens, Coin Purse snapshots the cart's shipping address,
shipping method and email, and puts them back if the sheet is cancelled, the payment fails or the
session expires.

A customer who closes the tab never sends a cancel, so run `coinpurse/sessions/reap` on a schedule
to restore those carts too.

## Shipping inside the sheet

With `requestShipping` on, the sheet opens with a placeholder rate while the first address is
priced, then shows your real Commerce shipping methods for the address the customer picks. Change
the address and the rates and totals update in the sheet.

Wallets often hide the full address until the customer authorises payment, handing over only a
country, region and postal code. Coin Purse prices from that, and validates the full address on
the way into the payment.

Both wallets refuse a sheet that asks for shipping and offers no rates, so a site with no shipping
methods enabled should render the button without `requestShipping`. Diagnostics warns about this.

## Other Twig helpers

```twig
{% if craft.coinpurse.isAvailable() %}<p>or pay in one tap</p>{% endif %}

{{ craft.coinpurse.driver() }}          {# 'stripe' or 'raw' #}
{{ craft.coinpurse.wasExpress(order) }} {# was this order placed through a wallet? #}
```

`isAvailable()` answers the server side of the question. Whether a particular customer's device has
a card in its wallet can only be answered in their browser, so the button itself stays hidden on
devices that cannot pay.

## From JavaScript

```twig
{{ craft.coinpurse.button({ cart: cart, js: 'window.payButton' }) }}
```

```js
window.payButton.refresh();   // re-read the cart after changing it on the page
window.payButton.reload();    // tear the button down and build it again
window.CoinPurse.lastOrder;   // { number, email } after a completed purchase
```

Item lists cannot be changed from JavaScript. They are signed by the server, which is the point.
Render a new button, or use `cart` mode and change the cart.

## Console commands

```sh
php craft coinpurse/diagnose         # why there is no button; exits non-zero if broken
php craft coinpurse/sessions/reap    # restore carts left addressed by abandoned sheets
php craft coinpurse/sessions/purge   # delete old session rows
php craft coinpurse/log/prune        # apply the log retention
```

## Events

```php
use craft\base\Event;
use justinholtweb\coinpurse\services\Checkout;
use justinholtweb\coinpurse\services\Drivers;
use justinholtweb\coinpurse\services\Quotes;

// Add a driver for another gateway.
Event::on(Drivers::class, Drivers::EVENT_REGISTER_DRIVERS, function($e) {
    $e->drivers[] = new MyWalletDriver();
});

// Relabel or reorder what the sheet shows. The total is re-read from the order afterwards.
Event::on(Quotes::class, Quotes::EVENT_AFTER_BUILD_QUOTE, function($e) {
    $e->quote->label = 'My Store';
});

// Refuse a sale before it is charged. The customer sees the message in the sheet.
Event::on(Checkout::class, Checkout::EVENT_BEFORE_PAY, function($e) {
    if (!myShipsTo($e->order)) {
        $e->isValid = false;
        $e->message = 'We can’t ship there yet.';
    }
});

// The order is complete and paid for.
Event::on(Checkout::class, Checkout::EVENT_AFTER_COMPLETE, function($e) {
    // $e->order
});
```

## Migrating from Web Payments

1. `composer remove ether/web-payments`, then install Coin Purse.
2. Point Coin Purse at the same gateway.
3. Change `craft.webPayments.button` to `craft.coinpurse.button`, or leave it: Coin Purse answers
   to the old name as well, unless you switch the alias off in the settings.

The option names are the same. The differences:

- Items are signed, and so cannot be changed from JavaScript. `window.payButton.items = [...]` is
  gone; `refresh()` and `reload()` remain.
- Apple Pay and Google Pay work on gateways other than Stripe.
