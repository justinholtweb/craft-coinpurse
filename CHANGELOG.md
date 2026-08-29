# Release Notes for Coin Purse

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
