# Softaware iyzico Payment for Magento 2

`softaware/module-iyzico-payment` (Magento module `Softaware_IyzicoPayment`)

iyzico payments for Magento Open Source and Adobe Commerce, built on iyzico's **Checkout Form**: customers enter
their card on iyzico's own secure form, so no card data ever reaches the store (PCI SAQ A). Installments,
3-D Secure, saved cards, "Pay with iyzico" and the other options switched on in your iyzico account come with the
form. Standard merchant accounts (not marketplace / sub-merchant).

Only the API key and the secret key are required. Everything else has a sensible default.

## Features

**Checkout and payment**
- Payment method "Credit / Debit Card (iyzico)" (title configurable) with the iyzico / Mastercard / Visa /
  American Express / Troy logo band. Offered only when the keys are set, the basket currency is one iyzico accepts
  (TRY, USD, EUR, GBP, NOK, CHF) and the total is above zero; country and min/max order total restrictions as usual.
- The order is placed in "Pending Payment" (stock reserved, no invoice, no order e-mail), then the customer goes to
  the payment page, which opens iyzico's Checkout Form:
  - **Redirect to iyzico's payment page** (default; works with every theme), or
  - **On this store's page**: iyzico's form in a frame inside your page (Luma and Hyvä templates). Only iyzico's
    payment page domain is added to the Content Security Policy (frame-src); no iyzico script runs on your pages.
- Payment page language: store view language (Turkish for Turkish store views, otherwise English), or fixed.
- Installment counts can be limited (for example 1, 3, 6); empty = whatever is set up in the iyzico panel.
- Reopening the payment page (refresh, back button, second tab) reuses the open form instead of starting a second
  payment.

**Payment result: never trusted from the browser**
- iyzico returns the customer to the store with a token. The store fetches the result from iyzico's API and only
  registers the payment when iyzico says SUCCESS **and** the basket ID and conversation ID are the order number, the
  currency is the order currency, the amount equals the order total that was sent, the amount charged is not less,
  and the response signature (HMAC-SHA256, docs "Response Signature Validation") is valid.
- Then the payment is captured on an invoice (transaction ID = iyzico payment ID), the order goes to "Processing" and
  the order e-mail is sent. Card association, family, type, BIN and last four digits, installments, amount charged,
  iyzico fees and the per-item payment transaction IDs (needed for refunds) are stored.
- Declined or abandoned: the order is cancelled (stock released), the basket restored and the customer sees a
  clear, translated message (iyzico error codes mapped to friendly texts; the raw code is logged and kept for the
  merchant). The customer can pay again with any method.
- iyzico's fraud check: a payment held for review (fraudStatus 0) puts the order in "Payment Review" with an open
  invoice. When iyzico approves it the invoice is paid and the order moves to "Processing" (order e-mail then); when
  iyzico rejects it the order is cancelled (iyzico gives the money back).

**Robustness**
- **Lost callback** (customer closed the tab, network error): a cron job every 5 minutes asks iyzico about orders
  still waiting after "Cancel Unpaid Orders After" (default 60 minutes): paid ones are invoiced, unpaid ones
  cancelled. An order is only cancelled after iyzico answered for every payment form of the order and for the order
  number itself, and none is paid or still under way (bank transfer / shopping credit can take days; those orders
  are kept and also protected from Magento's own "Pending Payment Order Lifetime" clean-up while iyzico reports them
  as under way).
- **Webhook** (merchant notifications) and **fraud notifications**: checked against X-IYZ-SIGNATURE-V3 when iyzico
  signs them, and in every case only used as a hint: the store re-reads the payment from the API. Unknown payments are
  acknowledged and ignored.
- **Races**: callback, webhook, cron, admin and customer actions on one order are serialised with a per-order lock and
  work on a freshly loaded order, so an order is invoiced or cancelled exactly once.
- **Second payments**: a payment that arrives for an order already paid by another payment, or already cancelled, is
  given back automatically (iyzico cancel, else full refund per item) and noted on the order.
- **Customer back in the basket** (back button from iyzico): the store asks iyzico; paid -> success page, not paid ->
  the order is cancelled and the basket restored.
- **Cancelling a waiting order** (admin, API, Magento's pending-order clean-up) is refused while iyzico has a payment
  for it, so a paid order is never cancelled by mistake.
- Callbacks, webhooks and cron also finish orders after the method has been switched off.
- The callback runs without a session, so iyzico's cross-site POST never replaces the customer's session cookie
  (same approach as Magento_Paypal); the customer lands on the result page with their own session.

**Amounts**
- Basket items are built from the order in the order currency: products (configurable/bundle parents once, dynamic
  bundles from their children) with their discounts, shipping as its own item; gift wrapping, fees, store credit,
  gift cards, reward points or rounding are reconciled to the grand total (extra charges as "Other charges", reductions
  spread in proportion). Items are never zero or negative and always sum exactly to the price, as iyzico requires.
- **Installment charge**: when the customer pays a surcharge for installments, iyzico's paidPrice is above the order
  total. Magento totals are not changed; the amount charged and the installment charge are shown in the admin iyzico
  panel and noted on the order, and refunds give the charge back in proportion (the last refund returns everything
  that is left).

**Refunds**
- Online refunds from the credit memo: full, partial, several partial refunds, shipping and adjustments. iyzico refunds
  per basket item, never more than an item's remaining paid amount; the module spreads the credit memo over the items.
- A full refund on the day of the payment uses iyzico's **cancel** (nothing appears on the card statement); if iyzico
  does not allow it, the module refunds instead.
- Nothing is recorded as refunded unless iyzico confirmed it; errors are shown in the admin.

**Admin**
- iyzico panel on the order, invoice and credit memo pages: status, payment ID, card, BIN, installments, amount
  charged, installment charge, iyzico fees, refunds made, sandbox badge, attention notes, and a **Check Status at
  iyzico** button (POST + form key, own ACL).
- Softaware > iyzico > **iyzico Transactions**: every payment form opened and its outcome (order, payment ID,
  amounts, currency, status, fraud check, installments, card, environment, error), filters, CSV export with
  spreadsheet-formula protection.
- **Test Connection** button and the webhook URL to register, in the payment method settings.
- ACL: View iyzico Transactions; Check Payment Status at iyzico; iyzico Settings and Connection Test.

**Storefront extras**
- Optional "Installment Options" table on product pages (default off; TRY only), loaded after the page from a cached
  endpoint (6 hours per amount, 5 s timeout, silent on failure), Luma and Hyvä.

**Headless**
- REST: after placing the order, get a signed payment page URL (valid 2 hours) for the order:
  - customer token: `GET /V1/softaware-iyzico/mine/orders/:orderId/payment-url`
  - guest: `GET /V1/softaware-iyzico/guest-carts/:cartId/orders/:orderId/payment-url` (the masked cart ID the order
    was placed from)
  Other orders answer "not found".

## Requirements

Magento Open Source / Adobe Commerce 2.4.7 - 2.4.9, PHP 8.2 - 8.5, `softaware/module-base`. Luma and Hyvä
storefronts (the checkout itself is Luma or Hyvä's Luma checkout fallback). No iyzico SDK needed.

## Installation

```
composer require softaware/module-iyzico-payment
bin/magento module:enable Softaware_IyzicoPayment
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode
bin/magento setup:static-content:deploy # production mode
```

Magento cron must run (the lost-payment and fraud-review checks are cron jobs).

## Setup

1. In the iyzico merchant panel (sandbox: https://sandbox-merchant.iyzipay.com) open Settings > Merchant Settings and
   copy the **API key** and **secret key**.
2. Stores > Configuration > Sales > Payment Methods > **iyzico (Softaware)** (also linked from Softaware > iyzico >
   Configuration): set Enabled = Yes, Environment, API key and secret key (website scope possible: each website can
   use its own iyzico account). Save, then press **Test Connection**.
3. Register the **Merchant Notification URL** shown there in the iyzico panel (Settings > Merchant Settings >
   Merchant Notifications), e.g. `https://www.example.com/softaware_iyzico/webhook/index`. Use the same URL for
   fraud review notifications (IFN) if your account has that setting. Ask iyzico (entegrasyon@iyzico.com) to switch
   on signed notifications (X-IYZ-SIGNATURE-V3).
4. Keep "Cancel Unpaid Orders After" below Magento's Stores > Configuration > Sales > Sales > Orders Cron Settings >
   "Pending Payment Order Lifetime" (default 480 minutes).

### Configuration paths

| Setting | Path | Default | Scope |
|---|---|---|---|
| Enabled | `payment/softaware_iyzico/active` | 0 | website |
| Title | `payment/softaware_iyzico/title` | Credit / Debit Card (iyzico) | store view |
| Environment | `payment/softaware_iyzico/environment` | sandbox (`sandbox` / `live`) | website |
| API key (encrypted) | `payment/softaware_iyzico/api_key` | | website |
| Secret key (encrypted) | `payment/softaware_iyzico/secret_key` | | website |
| Payment page | `payment/softaware_iyzico/display_mode` | redirect (`redirect` / `iframe`) | store view |
| Installment options | `payment/softaware_iyzico/installments` | empty = iyzico panel | website |
| Payment page language | `payment/softaware_iyzico/language` | auto (`auto` / `tr` / `en`) | store view |
| Installment table on product pages | `payment/softaware_iyzico/installment_table` | 0 | store view |
| Countries | `payment/softaware_iyzico/allowspecific`, `specificcountry` | all | website |
| Min / max order total | `payment/softaware_iyzico/min_order_total`, `max_order_total` | none | website |
| Sort order | `payment/softaware_iyzico/sort_order` | 10 | website |
| Order status during fraud review | `payment/softaware_iyzico/review_status` | payment_review | website |
| Cancel unpaid orders after (minutes) | `payment/softaware_iyzico/pending_timeout` | 60 (35 - 10080) | website |
| Identity number when none is given | `payment/softaware_iyzico/default_identity_number` | 11111111111 | website |
| Debug log | `payment/softaware_iyzico/debug` | 0 | website |

The identity number sent to iyzico is a valid T.C. Kimlik No from the customer's tax/VAT number (or the address VAT
field) when there is one, otherwise the default above (iyzico requires one).

## Testing in the sandbox

Sandbox cards (any future expiry date, any CVC in the right format; the sandbox 3-D Secure / OTP code is `123456`):

| Card | Result |
|---|---|
| 5526 0800 0000 0006 (Akbank Mastercard credit) | success |
| 4766 6200 0000 0001 (Denizbank Visa debit) | success |
| 5400 0100 0000 0004 | foreign card (success, or refused if your account blocks foreign cards) |
| 4111 1111 1111 1129 | insufficient funds |
| 4129 1111 1111 1111 | do not honour |
| 4124 1111 1111 1116 | invalid CVC |
| 4131 1111 1111 1117 | 3-D Secure failed (mdStatus 0) |
| 5406 6700 0000 0009 | success, but cannot be cancelled or refunded |

Full list: https://docs.iyzico.com/en/add-ons/test-cards

## CLI

```
bin/magento softaware:iyzico:check                 # run the scheduled check now (waiting and fraud-review orders)
bin/magento softaware:iyzico:check 000000123        # ask iyzico about one order and update it
bin/magento softaware:iyzico:check 000000123 --cancel-unpaid
```

## Logs

`var/log/softaware_iyzico.log`: errors and payment events always; full API requests and responses only with
"Debug Log" on. API keys, signatures, tokens and payment page URLs are shortened, personal data (name, e-mail,
phone, identity number, address, IP) is masked. iyzico never sends card numbers to the store.

## Security notes

- Card data never touches the store (iyzico-hosted form).
- Results come only from iyzico's API (authenticated with IYZWSv2 / HMAC-SHA256) and are checked against the order.
- Webhook signatures (X-IYZ-SIGNATURE-V3) are verified when present; notifications cannot change an order by
  themselves.
- Payment and result pages work only for the visitor's own just-placed order (checkout session) or with a signed,
  expiring link for that order (no order IDs in URLs).
- Redirects go only to iyzico's own HTTPS pages; the result page only to this store.
- Keys are stored encrypted, never logged and never shown back.

## Limitations

- Checkout Form only (no on-site card form, no pre-authorisation / later capture, no marketplace).
- Installment charges are not added to Magento's order totals (see above).
- Installments are generally available for TRY payments only (iyzico / Turkish banks); the product page table is shown
  for TRY prices only.
- A refund that spans several basket items and fails part-way cannot be undone; the module stops, shows what was
  refunded and saves nothing (record it with an offline credit memo).
- Multishipping checkout and admin-created orders are not supported.
