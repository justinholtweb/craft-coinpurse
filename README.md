# Coin Purse

Apple Pay and Google Pay **express checkout** for Craft Commerce 5.

A wallet button you can drop on a product page, a cart, a mini-cart or a checkout step, that
completes the whole order in one tap: the wallet sheet supplies the address and contact details,
the site quotes shipping live inside the sheet, and the order is placed without the customer ever
seeing a form.

```twig
{{ craft.coinpurse.button({
    items: [{ id: product.defaultVariant.id, qty: 1 }],
    requestShipping: 'delivery',
    onComplete: { redirect: '/thanks?number={number}' },
}) }}
```

## Why not just use the Stripe gateway?

`craftcms/commerce-stripe` already shows Apple Pay and Google Pay — inside the Payment Element, at
the **final payment step**, after the customer has filled in an email field, a shipping address
form, chosen a shipping method and clicked through two or three pages. That is a card-entry
convenience.

The wallet's real value is that it already holds a verified name, email, phone and shipping
address, and can hand all of it over in one gesture from the *first* page the customer sees. That
is what Coin Purse does, and it works on any gateway, not only Stripe.

It is also a replacement for `ether/web-payments`, which did this for Craft 3 and 4, last shipped
in April 2023, and never came to Craft 5. The Twig option names are deliberately the same, so a
site migrating off it can change the tag name and keep its templates. `craft.webPayments.button()`
even keeps working — see [Migrating from Web Payments](#migrating-from-web-payments).

## Requirements

- Craft CMS 5.3+
- Craft Commerce 5.0+
- PHP 8.2+
- **HTTPS.** Apple Pay and Google Pay refuse to run on anything else, and fail silently when they
  do — no error, no console message, just no button.

## Installation

```sh
composer require justinholtweb/craft-coinpurse
php craft plugin/install coinpurse
```

Then open **Coin Purse → Settings** and choose the gateway express payments should be charged
through. **Coin Purse → Diagnostics** will tell you what is still missing.

## The two drivers

Coin Purse can reach a wallet two ways, and picks automatically.

### Stripe

If your gateway is Stripe's Payment Intents gateway, Coin Purse drives Stripe's **Express Checkout
Element**. You need nothing beyond a working `craftcms/commerce-stripe` install with your domain
registered for Apple Pay in the Stripe dashboard — Stripe holds the certificate and hosts the
domain verification.

Commerce creates the PaymentIntent, the browser confirms it with the wallet's authorisation, and
Commerce finishes it. Transactions, order completion, status emails, captures and refunds are all
Commerce's own code.

### Direct (any other gateway)

For every other gateway, Coin Purse speaks Apple Pay JS and the Google Pay API itself and hands the
resulting token to the gateway's own payment form. Both wallets encrypt their token *to the
gateway*, so it passes through the site opaque.

This needs more setup:

- **Apple Pay** — a merchant ID, a merchant identity certificate, and Apple's domain association
  file. Paste the file's contents (or its path) into the settings and Coin Purse serves it at
  `/.well-known/apple-developer-merchantid-domain-association`.
- **Google Pay** — the gateway identifier Google should encrypt for (e.g. `braintree`) and your
  merchant ID at that gateway. No certificate.
- **The token field** — gateway plugins disagree about what the field holding a wallet token is
  called. Left empty, Coin Purse looks for a known name on the gateway's payment form; Diagnostics
  reports which one it found, and you can name it explicitly if the guess is wrong.

## Twig

### `craft.coinpurse.button(options)`

| Option | Type | What it does |
| --- | --- | --- |
| `items` | array | What to buy. Each entry is `{ id, qty, options, note }`, or just a purchasable ID. |
| `cart` | Order | Buy the customer's existing cart instead of `items`. |
| `requestShipping` | bool\|string | `false`, `true`, `'shipping'`, `'delivery'` or `'pickup'`. Changes the sheet's wording, and whether an address is collected at all. |
| `requestDetails` | array | Any of `name`, `email`, `phone`. Email is always collected — Commerce cannot complete an order without one. |
| `onComplete.redirect` | string | Where to send the customer afterwards. `{number}` is replaced with the order number. |
| `onComplete.js` | string | JavaScript to run afterwards. `cwp.number` and `cwp.email` are in scope. |
| `js` | string | A variable to assign the button to, e.g. `'window.payButton'`. |
| `style` | hash | `{ type, theme, height }`. Overrides the defaults from the settings. |

Returns an empty string — not an error, not a placeholder — whenever no button can be served, so
it is always safe to call.

### Everything else

```twig
{% if craft.coinpurse.isAvailable() %}<p>or pay in one tap</p>{% endif %}

{{ craft.coinpurse.driver() }}          {# 'stripe' or 'raw' #}
{{ craft.coinpurse.wasExpress(order) }} {# was this order placed through a wallet? #}
```

`isAvailable()` answers the server side of the question. Whether a *given customer's* device has a
card in its wallet is only ever answerable in their browser.

### From JavaScript

```js
window.payButton.refresh();   // re-read the cart after changing it on the page
window.payButton.reload();    // tear the button down and build it again
window.CoinPurse.lastOrder;   // { number, email } after a completed purchase
```

Item lists are **not** mutable from JavaScript, unlike in Web Payments. They are signed by the
server, which is the point — see below. Render a new button, or use `cart` mode and change the
cart.

## How it protects the store

**Button payloads are signed.** The purchasables, quantities, options and shipping requirements a
button was rendered with are signed with Craft's own security key. The express endpoints refuse
anything that does not verify, so a browser cannot substitute a cheap variant's ID for an expensive
one's, or invent a quantity. (Web Payments accepted whatever the browser posted.)

**Every number is the server's.** The browser renders the quote it is given and never adds anything
up. If the total moves between what the sheet last displayed and what the order costs — a rate that
changed under a redacted address, a discount that expired mid-sheet — the charge is refused rather
than quietly made for a different amount.

**Apple Pay merchant validation is allow-listed.** The validation URL arrives from the page, and a
page can be persuaded to send anything. Only Apple's own gateway hosts are ever contacted, so the
endpoint cannot be turned into a way to POST your merchant certificate somewhere else.

**The cart is not collateral damage.** A wallet sheet has to write an address onto the order to
price it, and customers dismiss sheets all the time. Coin Purse snapshots the cart's address,
shipping method and email first, and restores them on cancel, failure or expiry. A "buy now" button
on a product page does not touch the cart at all — it builds its own detached order.

## Console

```sh
php craft coinpurse/diagnose         # why there is no button; exits non-zero if broken
php craft coinpurse/sessions/reap    # restore carts left addressed by abandoned sheets
php craft coinpurse/sessions/purge   # delete old session rows
php craft coinpurse/log/prune        # apply the log retention
```

`coinpurse/diagnose` is worth putting in a deploy script. It stops a release that would have
shipped a checkout with no button on it — a failure nobody would otherwise notice until the week's
sales came in low.

`coinpurse/sessions/reap` is worth putting on a schedule. A customer who opens a wallet sheet and
then closes the tab never sends a cancel, so their cart is left holding whatever address the sheet
wrote to it.

## Extending

```php
use justinholtweb\coinpurse\services\Drivers;
use justinholtweb\coinpurse\services\Quotes;
use justinholtweb\coinpurse\services\Checkout;

// Add a driver for another gateway.
Event::on(Drivers::class, Drivers::EVENT_REGISTER_DRIVERS, function($e) {
    $e->drivers[] = new MyWalletDriver();
});

// Adjust what the sheet shows. The total is not yours to change.
Event::on(Quotes::class, Quotes::EVENT_AFTER_BUILD_QUOTE, function($e) {
    $e->quote->label = 'My Store';
});

// Refuse a sale before it is charged.
Event::on(Checkout::class, Checkout::EVENT_BEFORE_PAY, function($e) {
    if (!$this->shipsTo($e->order)) {
        $e->isValid = false;
        $e->message = 'We can’t ship there yet.';
    }
});

Event::on(Checkout::class, Checkout::EVENT_AFTER_COMPLETE, function($e) {
    // $e->order is complete and paid for.
});
```

## Migrating from Web Payments

1. `composer remove ether/web-payments`, then install Coin Purse.
2. Point Coin Purse at the same gateway.
3. Change `craft.webPayments.button` to `craft.coinpurse.button` — or leave it, because Coin Purse
   answers to the old name too, unless `ether/web-payments` is still installed or you switch the
   alias off in the settings.

The option names are the same. The differences worth knowing:

- Items are signed and therefore **not** mutable from JavaScript.
- `window.payButton.items = [...]` is gone. `refresh()` and `reload()` remain.
- Google Pay and Apple Pay work on gateways other than Stripe.

## Support

`justin@justinholt.com`

## Verification status

The Stripe driver is tested against a real `craftcms/commerce-stripe` install, and the whole express
flow — signed payload, detached order, wallet address, shipping quote, charge, completion — is
tested end to end against a live Commerce gateway. 113 integration checks.

The direct Apple Pay and Google Pay driver is written from Apple's and Google's published
specifications; its request building, token mapping and merchant-validation allow-list are covered
by the same suite. A live end-to-end charge through it needs an Apple merchant identity certificate
and a third-party gateway account, so that part ships documented rather than claimed as proven.
