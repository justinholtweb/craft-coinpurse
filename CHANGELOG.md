# Release Notes for Coin Purse

## 5.2.1 - 2026-11-08

### Security

- A bundled button script no longer trusts the endpoints in a `data-coinpurse-config` attribute.
  On a page that lets `data-*` attributes through from user content, a forged button could point
  the button script at another host, which then received the visitor's CSRF token. Endpoints must
  now be on the page's own origin, or the button is hidden and nothing is sent. The injected
  script, which 5.2.0 did not expose, gains the same check: no request or token goes to another
  origin. Only sites that turned off **Inject the button script** in 5.2.0 were affected.
- A redirect read from an attribute is followed only within the same site.

## 5.2.0 - 2026-11-08

### Added

- The button script can be bundled with your own JavaScript instead of loaded from Craft's asset
  URL. Turn off **Inject the button script** (`injectRuntime`) and Coin Purse writes nothing into
  the page but the button itself, which carries its configuration in a `data-coinpurse-config`
  attribute. Import `coinpurse.mjs` (ES modules) or `coinpurse.js` (CommonJS or a plain script),
  include `coinpurse.css`, and call `CoinPurse.start()`. It mounts every button on the page, waits
  for the document if it runs first, and can be called again for buttons added later. Stripe.js and
  Google Pay's script still load from their own servers, on demand.
- `CoinPurse.start({ onComplete: function (order, button) {} })`, and a `coinpurse:complete` event
  on the button element, fired on every completed purchase whichever way the script was loaded.
- A **Button script** line on the Diagnostics screen while the script is not injected.

### Changed

- Loading the script twice (injected and bundled) now leaves one instance, rather than the second
  copy replacing the first and forgetting its buttons. A button is only ever mounted once.
- A button the inline call reached before its markup existed is no longer registered as a dead
  button; the mount waits for the document instead.
- `craft.coinpurse.button()` returns an empty string, and logs the reason, if rendering throws,
  rather than taking the page down.

### Security

- A button configuration read from an attribute is treated as data. `onComplete.js` is never
  written into `data-coinpurse-config` and is ignored if found there, `js` is assigned as a
  variable name rather than evaluated, and an `onComplete.redirect` that is not a relative or
  http(s) URL is not followed. The injected script tag behaves exactly as before.

## 5.1.0 - 2026-10-10
### Added

- An **Express checkout** column for Commerce → Orders, showing the wallet each express order was
  paid with (Apple Pay, Google Pay, or Link, PayPal, Amazon Pay and Klarna through Stripe).
- An **Express checkout** condition rule for order conditions — *Any wallet*, a specific wallet, or
  *Not express checkout* — so staff can filter the orders index or build a custom source of wallet
  orders. It is kept off discount, shipping and gateway conditions on purpose: the wallet is only
  known once the customer authorises the payment, after the sheet has shown its total.
- `craft.coinpurse.wallet(order)` and `craft.coinpurse.walletLabel(order)`.

### Changed

- Completed express orders are now recorded permanently, with their wallet. `wasExpress(order)`
  used to answer from session rows, which are purged within hours, so it said "no" for almost every
  order older than a day. Paid sessions still on disk are carried over by the migration, with the
  wallet unknown.

## 5.0.2

Not released on its own; these changes ship in 5.1.0.

### Changed

- `coinpurse/sessions/purge` now deletes the unpaid order behind each abandoned buy-now (`items`
  mode) session along with the session row. Those orders were never a customer's cart, and a
  button's public payload could be replayed to create them; they used to stay until Commerce's
  incomplete-cart purge, three months by default. Carts, completed orders and orders with any
  transaction against them are never deleted.
- Craft's garbage collection now runs `coinpurse/sessions/reap` and `coinpurse/sessions/purge`, so an
  install without a scheduled task still cleans up. Scheduling them remains the recommended setup.

## 5.0.1

### Security

- Coin Purse's settings can now only be saved by admins, and only where `allowAdminChanges` is on.
  A user with only the `coinpurse-manage` permission could previously set the Apple Pay domain
  association to an environment variable or a file path, such as the security key or `.env`, and
  the public domain-association URL would then serve it to anyone.
- A domain association path is now only followed to a file named
  `apple-developer-merchantid-domain-association` inside the project. Anything else is not served.
- The anonymous `express/start` and Apple Pay merchant validation endpoints are now rate limited per
  IP address, since each call creates an order or makes a request to Apple.
- Errors on the anonymous express and Apple Pay endpoints no longer show internal details, such as
  certificate paths, to customers. Payment messages meant for customers, like a declined card, are
  still shown, and the full error is logged. In devMode the detail is still shown.

## 5.0.0

Initial release.

### Added

- `{{ craft.coinpurse.button(…) }}` — an Apple Pay / Google Pay express checkout button for any
  product page, cart, mini-cart or checkout step, completing a whole order in one tap.
- Two drivers: Stripe's Express Checkout Element for stores on `craftcms/commerce-stripe`, and a
  direct Apple Pay JS / Google Pay API driver for every other gateway.
- Live shipping quoting inside the wallet sheet — address changes and rate changes are priced by
  Commerce's own shipping methods without leaving the sheet.
- Signed button payloads, so the purchasables, quantities and options a button was rendered with
  are the only ones its session can ever charge for.
- Cart snapshots, restored when a customer dismisses the sheet, so an abandoned wallet sheet does
  not leave its address on the customer's cart.
- "Buy now" mode builds a detached order rather than touching the cart at all.
- A refusal to charge when the total moves between what the sheet displayed and what the order
  costs.
- Apple Pay merchant validation with a strict allow-list of Apple's own gateway hosts, and the
  domain association file served at `/.well-known/apple-developer-merchantid-domain-association`.
- A Diagnostics screen and `coinpurse/diagnose` console command that name what is stopping a button
  from appearing — the failure mode wallets are worst at reporting.
- An express checkout log, with payloads withheld by default because they contain addresses.
- Console commands for diagnostics, log pruning and reaping abandoned sessions.
- `craft.webPayments.button()` as an alias, so templates written against the abandoned
  `ether/web-payments` plugin keep working.
- Events for registering drivers, adjusting the quote, refusing a sale, and reacting to a completed
  express order.
