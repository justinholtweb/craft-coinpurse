# Coin Purse — plan

Apple Pay and Google Pay **express checkout** for Craft Commerce 5. A wallet button that can be
dropped on a product page, a cart, a mini-cart or a checkout step, and that completes a whole
order in one tap: the wallet sheet supplies the address and contact details, the site quotes
shipping live inside the sheet, and the order is placed without the customer ever seeing a form.

Package `justinholtweb/craft-coinpurse`, handle `coinpurse`, namespace `justinholtweb\coinpurse`.
**Single paid edition, $79.**

## Why it exists

`ether/web-payments` ($49 up front, $19/yr) did this for Craft 3 and 4. Its last release was
**April 2023**, it never shipped for Craft 5, and it only ever supported Stripe. Sites that used
it are stuck on Commerce 4 or have torn the buttons out.

The obvious objection is that `craftcms/commerce-stripe` 5.x *already* shows Apple Pay and Google
Pay. It does — inside the Payment Element, at the **final payment step**, after the customer has
already filled in an email field, a shipping address form, chosen a shipping method and clicked
through two or three pages. That is a card-entry convenience, not express checkout. The wallet's
real value is that it already holds a verified name, email, phone and shipping address, and can
hand all of it over in one gesture from the *first* page the customer sees.

Coin Purse is that gesture. It is also the only thing in the Craft ecosystem that offers it on
Craft 5.

Where it beats the plugin it replaces:

- **Craft 5 / Commerce 5**, obviously.
- **The cart is not collateral damage.** Web Payments wrote the wallet's address straight onto the
  live cart and left it there when the customer dismissed the sheet. Coin Purse snapshots the
  cart's address, shipping method and email before it touches anything, and restores the snapshot
  on cancel, failure or timeout.
- **Signed button payloads.** Web Payments' endpoints accepted whatever purchasable IDs, quantities
  and options the browser posted. Coin Purse signs the payload the button was rendered with, using
  Craft's own `Security::hashData()`, and refuses anything that does not match.
- **Two drivers, not one.** Stripe via the Express Checkout Element, plus a gateway-agnostic driver
  that talks Apple Pay JS and the Google Pay API directly and hands the wallet token to whatever
  gateway the store already uses.
- **Diagnostics.** Wallet buttons fail silently and invisibly by design — a misconfigured domain
  just renders nothing at all. A diagnostics screen that names the missing piece is worth more
  than any other feature here.
- **A connection log**, so "it didn't work on my phone" is answerable.

## Architecture

### The two invariants

1. **`services\Quotes::build()` is the only place money is computed.** The button's initial
   line items, the in-sheet shipping-address update, the shipping-rate update and the final charge
   all come out of one `Quote`. A wallet sheet that shows one total and charges another is the
   single worst bug this plugin could have, and there is exactly one place for it to happen.
2. **`services\Checkout::complete()` is the only place an express order is completed.** Every
   driver, every retry and every return from a 3-D Secure redirect lands there, so idempotency and
   order completion are decided once and cannot disagree.

### Order lifecycle

Two modes, and they are deliberately different:

- **`cart` mode** operates on the real session cart, so coupon codes, gift notes and custom fields
  survive. The pre-flight state is snapshotted into the session row and restored if the customer
  walks away.
- **`items` mode** ("buy now" from a product page) builds a **detached order** — its own order
  number, never placed in the session — so a one-tap purchase cannot disturb a cart the customer
  has been filling for twenty minutes.

An express session (`{{%coinpurse_sessions}}`) ties the two together: signature, order id, mode,
state, snapshot, transaction id. Its `uid` is what the browser holds; it never holds an order id.

### The Stripe flow (verified against `commerce-stripe` 5.x source, not guessed)

`PaymentIntents::authorizeOrPurchase()` creates an **unconfirmed** intent when the payment form
carries no `paymentMethodId`, and `PaymentIntentResponse::getRedirectData()` then returns
`client_secret` and `payment_intent`. That is the seam Commerce's own Payment Element uses, and
Coin Purse uses the same one — which means transactions, order completion, status emails, captures
and refunds are all Commerce's, not ours.

1. `express/start` → session, quote, client config.
2. Client mounts the Express Checkout Element with `mode: 'payment'` and the quote's amount.
3. `shippingaddresschange` → `express/address` → re-quote → `elements.update({amount})`.
4. `shippingratechange` → `express/shipping` → re-quote → `elements.update({amount})`.
5. `confirm` → `elements.submit()` → `express/pay`, which applies the final address and payer to
   the order and calls `Payments::processPayment()` with an empty `PaymentIntent` form. Returns the
   client secret.
6. Client `stripe.confirmPayment({elements, clientSecret, redirect: 'if_required'})`.
7. `express/complete` → `Payments::completePayment()`. If Stripe did redirect (3-D Secure), the
   return URL is Commerce's own `commerce/payments/complete-payment` and it lands in the same place.

The legacy `stripe.paymentRequest()` / `paymentRequestButton` path is deliberately **not** used.
It maps more neatly onto Commerce's legacy `paymentMethodId` field, but Stripe has superseded it,
and a new plugin should not be born on a deprecated element.

### The raw flow (Apple Pay JS + Google Pay API)

For stores on any other gateway. Coin Purse speaks the wallet protocols itself and hands the
resulting token to the gateway's own payment form:

- **Apple Pay** needs a merchant identity certificate and server-side merchant validation — a
  mutual-TLS POST to the validation URL the browser supplies. `services\ApplePay` does that, and
  serves the `/.well-known/apple-developer-merchantid-domain-association` file Apple requires.
- **Google Pay** needs no certificate; `PAYMENT_GATEWAY` tokenization has Google encrypt to the
  gateway's own key, so the token arrives ready for the gateway to decrypt.
- **Token delivery** is configurable: the attribute on the gateway's payment form that receives the
  token, and its encoding. Presets ship for the gateways whose form models are public.

This driver is written from the published Apple and Google specifications. It is exercised by the
test suite at the protocol level, but a live end-to-end charge needs a merchant certificate and a
gateway account, so it ships documented as such rather than claimed as verified.

### Wallet coverage

Apple Pay and Google Pay are on by default — that is what the plugin is for. Stripe's Express
Checkout Element can also surface Link, PayPal, Amazon Pay and Klarna from the same button; those
are individually opt-in per site, off by default, so nobody discovers an unconfigured PayPal button
on their product page.

## Twig API

```twig
{{ craft.coinpurse.button({
    items: [{ id: variant.id, qty: 1, options: { giftWrapped: 'yes' } }],
    requestShipping: 'delivery',
    requestDetails: ['name', 'phone'],
    onComplete: { redirect: '/thanks?number={number}' },
    js: 'window.payButton',
    style: { type: 'buy', theme: 'dark', height: 48 },
}) }}
```

The option names match `ether/web-payments` on purpose: a site migrating off it should be able to
change the tag name and keep going. `craft.coinpurse.isAvailable()`, `.driver()` and
`.diagnostics()` round the variable out.

## Scope

- `src/services/Quotes.php` — invariant 1
- `src/services/Checkout.php` — invariant 2
- `src/services/Sessions.php` — express sessions, snapshots, idempotency
- `src/services/Drivers.php` — driver registry + `EVENT_REGISTER_DRIVERS`
- `src/services/Buttons.php` — payload building, signing, markup
- `src/services/ApplePay.php` — merchant validation, domain association
- `src/services/Diagnostics.php` — the "why is there no button" screen
- `src/services/Log.php`
- `src/drivers/StripeDriver.php`, `src/drivers/RawDriver.php`
- Controllers: `ExpressController` (start/address/shipping/pay/complete/cancel),
  `ApplePayController`, `SettingsController`, `LogController`
- Console: `coinpurse/diagnose`, `coinpurse/log/prune`, `coinpurse/sessions/purge`
- `tests/integration/checks.php`

## Out of scope for 5.0.0

- Subscriptions and saved payment sources — express checkout is a guest-speed feature.
- Wallet buttons inside the control panel.
- Apple Pay on the Web for in-app browsers that do not expose `ApplePaySession`.
