---
title: FAQ
slug: faq
order: 50
summary: The questions that come up before putting a payment button on a live store.
---

**Doesn't the Stripe gateway already do Apple Pay and Google Pay?**

It shows them inside the Payment Element, at the last step of checkout, after the customer has
typed an email, filled in an address form and chosen a shipping method. That saves typing a card
number. Coin Purse puts the wallet on the first page instead: the product page or the cart. The
wallet supplies the name, email, phone and address, shipping is quoted inside the sheet, and the
order is placed with no form at all.

**Does it only work with Stripe?**

No. With Stripe it drives Stripe's Express Checkout Element and needs almost no setup. With any
other gateway it speaks Apple Pay and Google Pay directly and hands the encrypted token to the
gateway's own payment form.

**Is this a replacement for Web Payments?**

Yes. `ether/web-payments` did this for Craft 3 and 4, last shipped in April 2023 and never came to
Craft 5. The Twig option names are the same, and `craft.webPayments.button()` keeps working as an
alias.

**Can a customer change the price or the product in the browser?**

No. The items, quantities and options a button was rendered with are signed with your site's
security key, and every total comes from the server. The browser displays numbers; it never adds
them up.

**Will a buy-now button mess up the customer's cart?**

No. A button with `items` builds its own order and never touches the cart. A button with `cart`
uses the cart, and puts its address, shipping method and email back if the sheet is cancelled or
fails.

**What happens if the customer taps twice, or the network drops?**

Wallet sheets fire their completion more than once in real life. Every path into payment goes
through one place, which checks whether this session has already been paid before charging.

**Does it handle 3-D Secure?**

With the Stripe driver, yes, through Stripe and Commerce. With the direct driver, whatever the
gateway does with a wallet token applies.

**Who creates the transaction, sends the emails and handles refunds?**

Commerce. Coin Purse hands the payment to the gateway through Commerce's own payment flow, so
transactions, order status emails, captures and refunds are exactly as they are for any other
order.

**Does it load third-party scripts on every page?**

Only on pages with a button, and only the one the driver needs. A Stripe store never loads Google's
script. Stripe.js and Google Pay's script load from their own origins, as both require.

**How much is it?**

$79, one edition, everything included.
