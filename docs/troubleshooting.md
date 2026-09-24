---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: No button, a sheet that closes itself, a charge that was refused, and where to look for each.
---

Start with **Coin Purse → Diagnostics**, or `php craft coinpurse/diagnose`. Most problems are on it.
Then **Coin Purse → Log**, which records each express session as it starts, pays and completes,
with the reason for anything that failed.

## There is no button

A wallet button that cannot run does not show an error. It just does not appear. In order of
likelihood:

- **The page is not on HTTPS.** Both wallets refuse to run anywhere else.
- **No gateway is chosen** in Coin Purse's settings, or the gateway is disabled.
- **The device has no card in its wallet**, or the browser has no wallet at all. Desktop Chrome
  with no saved Google Pay card shows nothing. That is correct behaviour.
- **Apple Pay domain not verified.** For Stripe, register the domain in the Stripe dashboard. For
  the direct driver, set the domain association file and register the domain with Apple. Check
  that `/.well-known/apple-developer-merchantid-domain-association` returns the file.
- **The template has no `<html>` or `<body>`.** Craft injects the button's script into them. The
  markup renders and the script that makes it work does not.
- **Nothing purchasable.** The tag returns an empty string for a disabled or unavailable variant,
  or an empty cart.

## The sheet opens and then closes with an error

- **"Shipping unavailable" or similar.** The button asks for shipping and no shipping method
  matches the address. Enable a method that covers it, limit **Allowed shipping countries**, or
  render the button without `requestShipping`.
- **The session expired.** A sheet left open longer than the session lifetime (30 minutes by
  default) is refused. The customer can tap again.
- **An `EVENT_BEFORE_PAY` handler refused the sale.** Its message is shown in the sheet.

## "The total changed" and the charge was refused

Coin Purse compares the total the sheet last showed with what the order costs at the moment of
payment, and refuses to charge if they differ. A rate that changed once the full address was
revealed, or a discount that expired while the sheet was open, will do it. The customer can tap
again and see the new total. This is deliberate: charging a different amount from the one the
customer approved is a chargeback.

## "Invalid button" or a 400 from the express endpoints

The button's payload is signed with the site's security key. It fails to verify when:

- the page was cached across a change to `CRAFT_SECURITY_KEY`, or rendered on one environment and
  served by another with a different key
- something on the page edited the button's data attributes

Clear the page cache. A button rendered with the current key works.

## The direct driver cannot find the token field

Diagnostics reports **Wallet token field** as failing when Coin Purse does not recognise the
gateway's payment form. Find the attribute the gateway plugin expects for a wallet or network
token and enter it under **Token field**, with the right **Token encoding**.

## Apple Pay merchant validation fails

- The certificate and key must be the **merchant identity** pair, not the payment processing
  certificate, and in PEM format.
- The domain must be registered under that merchant ID in your Apple developer account.
- Coin Purse will only ever contact Apple's own Apple Pay gateway hosts. A validation URL pointing
  anywhere else is refused.

## A customer's cart kept the wallet's address

A sheet abandoned by closing the tab never sends a cancel. Schedule `coinpurse/sessions/reap`,
which restores the snapshot for any session that expired without finishing.
