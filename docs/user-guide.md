# iyzico Payment for Magento 2: User Guide

Take card payments with **iyzico's Checkout Form**. Customers type their card on iyzico's own secure form, on
iyzico's payment page or in a frame on your page, so no card data reaches your store (PCI SAQ A). The store reads every
result from iyzico's API and checks it before the order is invoiced. Declined and abandoned payments cancel the order
and restore the basket, a cron job finishes orders whose callback never came, and credit memos refund online at
iyzico.

- Storefront demo (Luma): https://demo.commercegears.com/impulse-duffle.html (add to basket, then checkout)
- Storefront demo (Hyvä): https://demo.commercegears.com/hyva/impulse-duffle.html
- Admin demo: https://demo.commercegears.com/try-admin/iyzico-payment
- Changelog: [CHANGELOG.md](../CHANGELOG.md) · Questions: [FAQ](faq.md)

The demo uses the iyzico sandbox and pounds sterling (GBP). iyzico accepts payments in currencies other than TRY only
from cards issued outside Türkiye, so pay with the sandbox card **4054 1800 0000 0007** (any future expiry date, any
CVC) and enter the code shown on the sandbox 3-D Secure page. Nothing is charged.

## 1. Requirements

| | |
| --- | --- |
| Magento | Open Source or Adobe Commerce 2.4.7 to 2.4.9 |
| PHP | 8.2 to 8.5 |
| Themes | Luma checkout; Hyvä storefront through Hyva_LumaCheckout (payment and result pages have Hyvä templates) |
| Other | `commercegears/module-core` (installed automatically); Magento cron must run |
| iyzico | A standard iyzico merchant account (not marketplace / sub-merchant) with its API key and secret key |
| Currency | The order currency must be TRY, USD, EUR, GBP, NOK or CHF |

No iyzico SDK is needed.

## 2. Installation

With Composer. Use the Composer keys from your account on commercegears.com (see
https://commercegears.com/composer-access/):

```bash
composer config repositories.commercegears composer https://repo.commercegears.com
composer config --auth http-basic.repo.commercegears.com PUBLIC_KEY PRIVATE_KEY
composer require commercegears/module-iyzico-payment
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode only
bin/magento setup:static-content:deploy # production mode only
bin/magento cache:flush
```

To update later: `composer update commercegears/module-iyzico-payment`, then the same `bin/magento` commands.

## 3. Quick start

1. In the iyzico merchant panel (sandbox: https://sandbox-merchant.iyzipay.com) open Settings > Merchant Settings and
   copy the **API key** and the **secret key**.
2. In Magento open **Stores > Configuration > Sales > Payment Methods > iyzico (CommerceGears)** (also linked from
   **CommerceGears > iyzico > Configuration**). Set Enabled = Yes, choose the Environment (Sandbox or Live), paste both
   keys and save.
3. Press **Test Connection**. It answers "Connected to iyzico (sandbox). The keys are valid." when the keys work.
4. Copy the **Merchant Notification URL** shown below the button and register it in the iyzico panel (Settings >
   Merchant Settings > Merchant Notifications). Ask iyzico (entegrasyon@iyzico.com) to switch on signed notifications
   (X-IYZ-SIGNATURE-V3).
5. Place a test order with a sandbox card (see section 10).

Keys can be set per website, so each website can use its own iyzico account.

## 4. Settings

Stores > Configuration > Sales > Payment Methods > iyzico (CommerceGears):

| Setting | What it does |
| --- | --- |
| Enabled | Offers the method at checkout. Callbacks, notifications and the cron job still finish open orders when it is switched off. |
| Title | Name of the method at checkout (default "Credit / Debit Card (iyzico)"). |
| Environment | Sandbox or Live. Sandbox keys start with "sandbox-". |
| API Key, Secret Key | Stored encrypted and never shown again. |
| Test Connection | Checks the saved keys against iyzico. |
| Merchant Notification URL | The address to register in the iyzico panel. |
| Payment Page | "Redirect to iyzico's payment page" (default) or "On this store's page" (iyzico's form in a frame). |
| Installment Options | Limit the installment counts offered (1, 2, 3, 4, 6, 9, 12). Leave all unselected to offer what is set up in your iyzico panel. |
| Payment Page Language | From the store view (Turkish for Turkish store views, otherwise English), or fixed Turkish or English. |
| Show Installment Options on Product Pages | Adds an installment table to product pages for TRY prices. Loads after the page from a cache. |
| Payment from Applicable / Specific Countries | Usual country restriction. |
| Minimum / Maximum Order Total | Usual order total limits. |
| Sort Order | Position among the payment methods. |

**Advanced**

| Setting | What it does |
| --- | --- |
| Order Status While iyzico Reviews a Payment | Status used while iyzico's fraud check holds a payment. Do not ship until iyzico approves it. |
| Cancel Unpaid Orders After (Minutes) | Orders still waiting for payment are checked with iyzico after this time (default 60, 35 to 10080). Keep it below Magento's "Pending Payment Order Lifetime" (Stores > Configuration > Sales > Sales > Orders Cron Settings). |
| T.C. Kimlik No Attribute | The customer or address attribute holding the buyer's identity number, which iyzico requires. Only a valid number is sent. |
| Identity Number When None Is Given | Sent when nothing is mapped or the value is empty or invalid (default 11111111111). |
| Debug Log | Writes API requests and responses to `var/log/commercegears_iyzico.log`, with keys, tokens and personal data masked. Errors are always logged. |

## 5. The checkout

The method "Credit / Debit Card (iyzico)" shows the iyzico, Mastercard, Visa, American Express and Troy logo band. It
is offered when the keys are set, the basket currency is supported and the total is above zero.

1. The customer places the order. It is created in **Pending Payment**: stock is reserved, there is no invoice and no
   order e-mail yet.
2. The customer pays on iyzico's form: on iyzico's payment page (Redirect) or in a frame on your "Secure Payment" page
   (On this store's page). 3-D Secure, installments, saved cards and Pay with iyzico come with the form, as switched on
   in your iyzico account.
3. iyzico sends the customer back. The store reads the result from iyzico's API and checks that the order number,
   currency and amount match the order and that the response signature is valid.
4. **Paid**: the payment is captured on an invoice (transaction ID = iyzico payment ID), the order moves to
   **Processing**, the order e-mail is sent and the customer sees the success page.
5. **Declined or 3-D Secure failed**: the order is cancelled, stock released, the basket restored and the customer
   sees a clear message on the basket page, for example "The 3-D Secure verification was not completed. Please try
   again."

If the customer presses Back on iyzico's page, or opens the payment page again, the store asks iyzico first: an open
form is reused, a paid order goes to the success page, and an unpaid one is cancelled with the basket restored.

## 6. Orders that are not finished at once

**Lost callback.** If the customer closes the tab after paying, or the return to your store fails, the cron job
`commercegears_iyzico_check_pending` (every 5 minutes) asks iyzico about orders older than "Cancel Unpaid Orders
After". Paid orders are invoiced. Orders iyzico has no payment for are cancelled. Bank transfer and shopping credit
payments still under way are kept and protected from Magento's own pending order clean-up. A card payment left in
3-D Secure (the customer closed the bank's page) is treated as unpaid once the order is 3 hours old.

**Fraud review.** When iyzico's fraud check holds a payment, the order goes to Payment Review with an open invoice.
When iyzico approves it, the invoice is paid, the order moves to Processing and the e-mail is sent; when iyzico
rejects it, the order is cancelled and iyzico gives the money back.

**Second or late payments.** A payment that arrives for an order already paid by another payment, or already
cancelled, is given back automatically (iyzico cancel, else a full refund) and noted on the order.

**Cancelling by hand.** A waiting order cannot be cancelled in the admin while iyzico has a payment for it. Press
"Check Status at iyzico" instead.

## 7. The iyzico panel on orders

Sales > Orders > open an order > Payment Information shows the iyzico panel (also on invoices and credit memos): a
SANDBOX badge for sandbox payments, status, payment ID, card (association, family, last four digits), card type, BIN,
installments, amount charged, installment charge, iyzico fees, identity number source and every refund made at
iyzico. Orders still waiting for payment or in fraud review have a **Check Status at iyzico** button that asks iyzico
and updates the order straight away.

## 8. Transactions grid

CommerceGears > iyzico > **iyzico Transactions** lists every payment form opened: date, order, payment ID, status
(Form opened, Paid, Fraud review, Failed, Given back and others), order amount, amount charged, refunded amount, currency,
installments, fraud check, card, last four digits, environment and iyzico's error code and message. Filter it and
export it to CSV (cells that could be read as spreadsheet formulas are neutralised).

## 9. Refunds

Create a credit memo from the invoice and press **Refund** (online). Full refunds, partial refunds and several partial
refunds in a row are supported, including shipping and adjustments. iyzico refunds per basket item; the module spreads
the credit memo over the items. A full refund on the day of the payment uses iyzico's **cancel**, so nothing appears
on the card statement; if iyzico does not allow it, the module refunds instead. Nothing is recorded as refunded unless
iyzico confirmed it, and iyzico's error is shown if it refuses. An installment charge is refunded in proportion.

## 10. Testing in the sandbox

Set Environment = Sandbox with your sandbox keys. Sandbox cards (any future expiry date, any CVC):

| Card | Use |
| --- | --- |
| 5526 0800 0000 0006 | Turkish card, success (TRY stores only) |
| 4111 1111 1111 1129 | Turkish card, insufficient funds (TRY stores only) |
| 4054 1800 0000 0007 | Card issued outside Türkiye, success in any supported currency |
| 5400 0100 0000 0004 | Foreign card, success in any supported currency |

The sandbox 3-D Secure page shows the code to enter. To test a failed 3-D Secure check, press Cancel on that page.
Full list of test cards: https://docs.iyzico.com/en/add-ons/test-cards

## 11. Installments

Customers choose installments on iyzico's form. Rates and the offered banks are managed in the iyzico panel; "Installment
Options" limits the counts. Installments are generally available for TRY payments only. When the customer pays an
installment surcharge, iyzico charges more than the order total: Magento's totals are not changed, and the amount
charged and the installment charge are shown in the iyzico panel and noted on the order.

The optional product page table ("Show Installment Options on Product Pages") shows each card programme with the
monthly and total amount per installment count. It appears for TRY prices only.

## 12. CLI

```bash
bin/magento commercegears:iyzico:check                       # run the scheduled check now
bin/magento commercegears:iyzico:check 000000123              # ask iyzico about one order and update it
bin/magento commercegears:iyzico:check 000000123 --cancel-unpaid
```

## 13. Headless stores

After placing the order, get a signed payment page URL (valid 2 hours):

- customer token: `GET /V1/commercegears-iyzico/mine/orders/:orderId/payment-url`
- guest: `GET /V1/commercegears-iyzico/guest-carts/:cartId/orders/:orderId/payment-url`

Other customers' orders answer "not found".

## 14. Permissions (ACL)

Under CommerceGears > iyzico: **View iyzico Transactions**, **Check Payment Status at iyzico**, **iyzico Settings and
Connection Test**.

## 15. Troubleshooting

- **Test Connection fails with "api credentials are not found"**: the keys do not belong to the chosen environment
  (sandbox keys start with "sandbox-") or were pasted with extra characters.
- **The method is not shown at checkout**: check Enabled, both keys, the currency (TRY, USD, EUR, GBP, NOK, CHF),
  countries and order total limits.
- **"You can not pay foreign currency with local credit card" on iyzico's form**: the store currency is not TRY and the
  card was issued in Türkiye. iyzico accepts such cards only for TRY payments.
- **Orders stay in Pending Payment**: Magento cron is not running, or iyzico reports the payment as still under way.
  Press "Check Status at iyzico" or run the CLI check.
- **3-D Secure does not open in the frame** ("On this store's page"): your store enforces a Content Security Policy
  (restrict mode) on storefront pages. During 3-D Secure the frame moves to iyzico's and the bank's pages, which
  cannot be listed in advance; use "Redirect to iyzico's payment page".
- **More detail**: switch on Debug Log and read `var/log/commercegears_iyzico.log`.

## 16. Limitations

- Checkout Form only: no card form on your own page, no pre-authorisation with later capture, no marketplace.
- Installment charges are not added to Magento's order totals.
- Multishipping checkout and orders created in the admin are not supported.
- A refund spread over several basket items that fails part-way stops and shows what was refunded; record the rest
  with an offline credit memo.

## 17. Uninstall

Switch the method off and wait until no order is waiting for an iyzico payment, then:

```bash
composer remove commercegears/module-iyzico-payment
bin/magento setup:upgrade
```

Orders paid with iyzico keep their invoices, credit memos and payment details.
