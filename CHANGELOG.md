# Changelog

All notable changes to `softaware/module-iyzico-payment` are documented here.

## 1.0.0 - 2026-10-06

### Added
- iyzico Checkout Form payment method (Magento payment gateway adapter): order placed in Pending Payment, then
  iyzico's hosted form by redirect (default) or in a frame on the store's page (Luma and Hyvä); open forms are reused.
- Results fetched from iyzico's API and verified (status, basket ID, conversation ID, currency, amount, amount
  charged, HMAC-SHA256 response signature) before the payment is captured on an invoice; card, installments, fees
  and per-item payment transaction IDs stored; order e-mail sent after payment.
- Declines and abandoned payments: order cancelled, basket restored, friendly translated messages for iyzico error
  codes.
- Fraud review: Payment Review with an open invoice, approved or cancelled automatically (cron, fraud notification,
  admin check).
- Cron (every 5 minutes) for lost callbacks and fraud reviews; orders are cancelled only when iyzico confirms there is
  no payment; payments still under way (bank transfer, shopping credit) are kept.
- Webhook endpoint (X-IYZ-SIGNATURE-V3 verified when sent; always re-checked with the API).
- Per-order locking (callback, webhook, cron, admin, customer); automatic reversal of duplicate or late payments;
  cancelling a waiting order that iyzico reports as paid is refused.
- Online refunds from credit memos (full, partial, multiple; spread over basket items; same-day full refund via
  iyzico cancel; installment charges refunded in proportion).
- Admin iyzico panel with "Check Status at iyzico", Softaware > iyzico > iyzico Transactions grid with CSV export,
  Test Connection button, webhook URL display, ACL.
- Optional product page installment table (cached, loaded after the page, Luma and Hyvä).
- REST endpoints for headless storefronts: signed payment page URL for the customer's or guest cart's order.
- CLI `softaware:iyzico:check`.
- English and Turkish translations.
