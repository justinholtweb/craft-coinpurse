# Coin Purse — Craft CMS 5 Plugin

## Project Overview

Coin Purse puts an Apple Pay / Google Pay **express checkout** button anywhere on a Craft Commerce
5 site: product page, cart, mini-cart, checkout step. One tap completes the whole order — the
wallet sheet supplies the address and contact details, the site quotes shipping live inside the
sheet, and no form is ever shown. Distributed as `justinholtweb/craft-coinpurse`.
**Single paid edition, $79.** No edition gating anywhere.

## Why it exists

`ether/web-payments` ($49 + $19/yr) did this for Craft 3 and 4, last shipped **April 2023**, never
came to Craft 5, and only ever supported Stripe.

The obvious objection — that `craftcms/commerce-stripe` already shows Apple/Google Pay — misses
where. It shows them **inside the Payment Element at the final payment step**, after the customer
has filled in an email field, an address form and picked a shipping method. That is card-entry
convenience. Express checkout is the wallet handing over a verified name, email, phone and address
in one gesture from the *first* page the customer sees. Nothing else in the Craft 5 ecosystem does
it.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step: `src/web/assets/express/dist/coinpurse.js` and `.css` are plain files
- No vendored third-party code. Stripe.js and Google's `pay.js` must load from their own origins
  (Stripe requires it and SAQ-A depends on it), and are fetched on demand by whichever driver needs
  them — a Stripe store never loads Google's script

## Architecture

### Namespace & package

- Namespace: `justinholtweb\coinpurse`
- Package: `justinholtweb/craft-coinpurse`
- Handle: `coinpurse`

### The two invariants

1. **`services\Quotes::build()` is the only place money is added up.** The sheet's opening line
   items, the totals after an address change, the totals after a rate change and the amount finally
   charged are all one `Quote`, taken from the order at that instant. No driver is given the pieces
   to compute a total of its own. `EVENT_AFTER_BUILD_QUOTE` handlers may relabel and reorder, and
   the total is re-asserted from the order afterwards so a well-meaning handler cannot break it.
   The failure mode is uniquely bad: a wallet sheet is a system dialog the customer trusts more
   than the site, and one that says $42 while charging $46 is a chargeback and an Apple complaint.
2. **`services\Checkout::complete()` is the only place an express order is completed.** Every
   driver, every retry and every return from a 3-D Secure redirect lands there, so "have we already
   taken this customer's money?" is answered once, from the session row. Wallet sheets fire their
   completion handler more than once — flaky networks, double taps, restored tabs.

### The Stripe flow (read out of `commerce-stripe`, not guessed)

`PaymentIntents::authorizeOrPurchase()` creates an **unconfirmed** intent when the payment form
carries no `paymentMethodId`; `PaymentIntentResponse::getRedirectData()` then returns
`client_secret` and `payment_intent`. That is the seam Commerce's own Payment Element uses. So:
Commerce creates and finishes the intent, the browser confirms it, and transactions, order
completion, status emails, captures and refunds stay Commerce's code.

`StripeDriver::createPaymentForm()` returning a form with **no** `paymentMethodId` is load-bearing,
not an oversight. A test reads `commerce-stripe`'s own source to check that branch still exists.

The legacy `stripe.paymentRequest()` / `paymentRequestButton` pairing maps more neatly onto
Commerce's legacy `paymentMethodId` field and would save a round trip. Deliberately not used:
Stripe has superseded it.

### Order lifecycle

- **`items` mode** builds a **detached order** — its own number, never in the session — so a
  one-tap purchase cannot disturb a cart the customer has been filling for twenty minutes.
- **`cart` mode** uses the real cart, so coupon codes, gift notes and custom fields survive, and
  snapshots its address / shipping method / email into the session row first. `Sessions::restore()`
  puts them back on cancel, failure or expiry. Web Payments left the wallet's address on the cart.

### Security model

The express endpoints are anonymous by necessity. What stands in for authentication:

- **Signed payloads.** `helpers\Signature` wraps `Security::hashData()`. Items, quantities,
  options, shipping requirements, site and gateway are signed; presentation is not. Web Payments
  charged for whatever purchasable IDs the browser posted.
- **Server-side totals only.** `expectedTotal` from the client is compared, never trusted; a
  mismatch refuses the charge.
- **`services\ApplePay::assertValidationUrlIsApple()`** is an SSRF boundary. `validationURL` comes
  from the page, and without the allow-list an attacker could make the site POST its merchant
  identity certificate to a host of their choosing. Apple's documented hosts are
  `[cn-]apple-pay-gateway[-cert].apple.com`; the pattern is a shade wider to cover the regional pod
  hostnames Apple warns it may issue, and can still only ever reach an `apple.com` host.

## Traps found while building this

- **`yii\base\Model::toArray()`'s third parameter is untyped.** Writing `bool $recursive` in an
  override is a *compile-time fatal* that takes the whole plugin down, not a warning.
- **`Order::recalculate()` throws on an unsaved order** ("Do not recalculate an order that has not
  been saved"). A detached order has to be saved empty, then filled, then re-priced, then saved.
- **Craft refuses to route a template whose filename starts with `_`.** They are partials. A test
  fixture named `_coinpurse-fixture.twig` 404s.
- **A template with no `<html>`/`<body>` silently drops registered CSS and JS.** The button's
  markup renders and the script that makes it work does not. This bit the test suite before it bit
  a customer.
- **`yii\web\Response` has no `setContent()`** — `content` is a plain public property.
- **`Controller::beforeAction()` is a fixed public signature.** Narrowing it to `protected` is a
  compile fatal.
- **`action` is Craft's own query-string parameter**, so a CP log filter cannot be called `action`.
- **Wallets redact addresses mid-sheet** — often to a country, region and postal code — so orders
  are saved with validation off while a sheet is open, and validated on the way into
  `processPayment()`. Treating a redacted address as invalid breaks shipping quotes for everyone.
- **Apple Pay and Google Pay want major-unit decimal strings**; Stripe and this plugin work in
  minor units. `Quote::$decimals` exists so the browser can convert without a currency table.
- **Both wallets refuse a sheet that asks for shipping but offers no rates**, with an error the
  customer sees. `ButtonOptions::PENDING_SHIPPING_OPTION` is the placeholder that keeps the sheet
  open for the one round trip it takes to price the first address; it is rejected server-side if it
  ever reaches a charge.
- **Craft's CSRF token cannot be baked into the button**, because a cached page would ship a stale
  one. The JS fetches it from `users/session-info` on first use.
- **`(int)($amount * 100)` turns 8.30 into 829** on a great many builds. `helpers\Money` exists for
  that reason alone.

See `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps, and
`[[project_craft_shipper]]` / `[[project_craft_freeride]]` for the sibling Commerce plugins whose
conventions this follows.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-coinpurse/tests/integration/checks.php    # 113 checks
ddev exec bash /var/www/craft-coinpurse/tests/integration/cp-smoke.sh  # CP screens render
ddev exec bash -c 'find /var/www/craft-coinpurse/src -name "*.php" -print0 | xargs -0 -n1 php -l'
node --check src/web/assets/express/dist/coinpurse.js
```

The suite runs a **whole express purchase** through Commerce's bundled Dummy gateway — signed
payload, detached order, wallet address, shipping quote, charge, completion — and validates the
Stripe driver against a real installed `craftcms/commerce-stripe` 5.1. It also does live HTTP:
renders a button from a real front-end template, verifies the signature in the emitted markup, and
opens a session with it. Fixtures, gateways, session rows, log rows and settings are all restored
in a `finally`.

**Harness notes.** Three sibling plugins in this shared harness break things that have nothing to do
with Coin Purse, and `checks.php` works around each in-process (never persisted):

- `craft-penny` registers a `beforeSaveElement` handler typed `ModelEvent` while Craft passes an
  `ElementEvent`, so every element save fatals.
- `craft-lyfe` handles `Order::EVENT_AFTER_COMPLETE_ORDER` and reads a `phone` custom field that
  does not exist here, so *completing any order at all* throws from inside Commerce's payment
  pipeline, after the money has moved.
- `craft-friends` fatals rendering any 404 page (`Setting unknown property:
  craft\web\RedirectRule::template`), so a legitimate 404 arrives as a 500.

## Coding conventions

- `Craft::t('coinpurse', '…')` for user-facing strings; `src/translations/en/coinpurse.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required` — it blocks a fresh install
- The settings screen is served from a plugin CP route, so Craft is **not** namespacing it: field
  names are written `settings[foo]`. On Craft's generic plugin-settings page the opposite is true
- Anything that runs while a wallet sheet is open fails **towards a closed sheet with a message**,
  never towards a charge
