# iyzico Payment for Magento 2: FAQ

**What kind of iyzico account do I need?**
A standard iyzico merchant account (not a marketplace or sub-merchant account). You need its API key and secret key
from the iyzico merchant panel (Settings > Merchant Settings). Sandbox keys start with "sandbox-"; live and sandbox
keys are different. The module does not create iyzico accounts.

**Do card details pass through my store?**
No. The customer types the card on iyzico's own Checkout Form, either on iyzico's payment page or in a frame on your
page. The store only receives a token and then reads the result from iyzico's API, so no card number ever reaches
Magento (PCI SAQ A).

**Redirect or "On this store's page": which should I use?**
Both show iyzico's own form. Redirect (the default) sends the customer to iyzico's payment page and works with every
theme. "On this store's page" shows the same form in a frame between your header and footer (Luma and Hyvä
templates); only iyzico's payment page domain is added to the Content Security Policy and no iyzico script runs on
your pages.

**Which currencies are supported?**
TRY, USD, EUR, GBP, NOK and CHF. The method is only offered when the basket currency is one of these. Note iyzico's
rule for currencies other than TRY: they are only accepted from cards issued outside Türkiye (Turkish cards are
refused by iyzico's form), and your iyzico account must be allowed to take foreign cards and that currency.

**How do I know a payment is genuine?**
The store never trusts the browser. After the customer returns, it fetches the result from iyzico's API and registers
the payment only when iyzico reports success and the order number, currency, amount and response signature all
match. Only then is the invoice captured (transaction ID = iyzico payment ID), the order moved to Processing and the
order e-mail sent.

**What happens when a card is declined or the customer gives up?**
The order is cancelled (stock released), the basket is restored and the customer sees a clear message, for example
"Your card does not have enough limit or balance for this payment" or "The 3-D Secure verification was not
completed". The raw iyzico code is kept in the transactions grid and the log for you. The customer can pay again
with any payment method.

**What if the customer closes the tab after paying?**
A cron job runs every 5 minutes and asks iyzico about orders still waiting after "Cancel Unpaid Orders After"
(default 60 minutes). Paid orders are invoiced, unpaid ones cancelled. Payments still under way (bank transfer,
shopping credit) are kept. A card payment left in 3-D Secure is treated as unpaid once the order is 3 hours old. You
can also press "Check Status at iyzico" on the order, or run `bin/magento commercegears:iyzico:check <order number>`.

**Can I refund from Magento?**
Yes. Create a credit memo from the invoice and press Refund: full, partial and several partial refunds, including
shipping and adjustments, are refunded online at iyzico. A full refund on the day of the payment uses iyzico's cancel,
so nothing appears on the card statement. Nothing is recorded as refunded unless iyzico confirmed it.

**Are installments supported?**
Yes, through iyzico's form: the customer picks an installment option there. You can limit the counts offered (for
example 1, 3 and 6); rates are managed in the iyzico panel. Installments are generally available for TRY payments
only. An optional "Installment Options" table on product pages is shown for TRY prices. An installment charge paid by
the customer is shown in the iyzico panel and noted on the order; it is not added to Magento's order totals.

**Why does iyzico need an identity number?**
iyzico requires the buyer's T.C. Kimlik No. Map the customer or address attribute that holds it in "T.C. Kimlik No
Attribute"; only a valid number (11 digits with checksum) is sent. Without a mapping, the number set in "Identity
Number When None Is Given" (default 11111111111) is sent, and the admin is told so on each order.

**Do I need to set up iyzico's merchant notifications?**
It is recommended. Register the Merchant Notification URL shown in the settings in the iyzico panel and ask iyzico to
switch on signed notifications (X-IYZ-SIGNATURE-V3). A notification only makes the store ask iyzico again; nothing in
it is trusted. Without notifications the callback and the cron job still finish every order.

**Does it work with Hyvä and headless stores?**
Yes. On Hyvä the checkout is the Luma checkout fallback; the payment and result pages have Hyvä templates. Headless
stores can place the order and then get a signed payment page URL from the REST API.

**Can I try it?**
Yes, on the demo store: add a product to the basket and choose "Credit / Debit Card (iyzico)" at checkout. The demo
uses the iyzico sandbox and GBP, so pay with the sandbox card 4054 1800 0000 0007 (any future expiry date, any CVC)
and enter the code the sandbox 3-D Secure page shows. Nothing is charged.
