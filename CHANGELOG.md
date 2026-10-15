# Changelog

## 1.1.0 (2026-10-15)
New: German translation of the checkout and payment texts.

## 1.0.1 (2026-10-14)
- Fix: valid API keys were refused by iyzico, so Test Connection and payments failed.
- Fix: orders whose customer left the 3-D Secure page are cancelled after 3 hours instead of waiting.
- Improvement: a failed 3-D Secure check now tells the customer so, instead of a general bank message.
- Improvement: the transactions grid marks payment forms of unpaid, cancelled orders as failed.

## 1.0.0 (2026-10-11)
- New: first release with iyzico Checkout Form payments by redirect or in a frame (Luma and Hyvä).
- New: payments verified before capture; declined and abandoned orders cancelled, basket restored.
- New: fraud reviews, lost callbacks and duplicate payments handled automatically.
- New: online full and partial refunds from credit memos, and a transactions grid with CSV export.
- New: optional installment table, REST endpoints for headless stores, English and Turkish.
