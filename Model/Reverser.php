<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\Phrase;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Api\Client;

/**
 * Gives a payment back in full: a payment that arrived for an order that was already paid by another payment
 * or already cancelled. Tries iyzico's cancel first (same day, no entry on the card statement), then refunds every
 * basket item. Anything it cannot reverse is left for the merchant with a clear order comment.
 */
class Reverser
{
    public function __construct(
        private readonly Client $client,
        private readonly TransactionRepository $transactions,
        private readonly Config $config,
        private readonly Log $log
    ) {
    }

    /**
     * @return bool true when iyzico confirmed the full reversal
     */
    public function reverse(Order $order, Transaction $transaction, array $result, Phrase $reason): bool
    {
        $storeId = (int)$order->getStoreId();
        $paymentId = (string)$result['paymentId'];
        $paid = (float)($result['paidPrice'] ?? 0);
        $currency = (string)($result['currency'] ?? $order->getOrderCurrencyCode());
        $done = false;
        $error = '';

        try {
            $cancel = $this->client->post(Client::PATH_CANCEL, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => $order->getIncrementId(),
                'paymentId' => $paymentId,
            ], $storeId);
            if (($cancel['status'] ?? '') === 'success') {
                $done = true;
            } else {
                $error = trim(($cancel['errorCode'] ?? '') . ' ' . ($cancel['errorMessage'] ?? ''));
                $done = $this->refundItems($order, $result, $currency, $error);
            }
        } catch (ApiException $e) {
            $error = $e->getMessage();
        }

        $transaction->setData('payment_id', $paymentId);
        $transaction->setData('paid_amount', $paid);
        if ($done) {
            $transaction->setData('status', Transaction::STATUS_REVERSED);
            $transaction->setData('refunded_amount', $paid);
            $order->addCommentToStatusHistory(__(
                'iyzico payment %1 (%2 %3) was given back automatically: %4',
                $paymentId,
                Amount::format($paid),
                $currency,
                $reason
            ));
            $this->log->info('Payment reversed', ['order' => $order->getIncrementId(), 'paymentId' => $paymentId]);
        } else {
            $transaction->setData('status', Transaction::STATUS_PAID);
            $transaction->setData('error_message', mb_substr('Not reversed: ' . $error, 0, 255));
            $order->addCommentToStatusHistory(__(
                'iyzico payment %1 (%2 %3) needs your attention: %4 It could not be given back automatically (%5). Please refund it in the iyzico merchant panel.',
                $paymentId,
                Amount::format($paid),
                $currency,
                $reason,
                $error
            ));
            $this->log->error('Payment could not be reversed', [
                'order' => $order->getIncrementId(), 'paymentId' => $paymentId, 'error' => $error,
            ]);
        }
        $this->transactions->save($transaction);
        return $done;
    }

    private function refundItems(Order $order, array $result, string $currency, string &$error): bool
    {
        $storeId = (int)$order->getStoreId();
        $items = (array)($result['itemTransactions'] ?? []);
        if (!$items) {
            return false;
        }
        foreach ($items as $item) {
            $amount = (float)($item['paidPrice'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $refund = $this->client->post(Client::PATH_REFUND, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => $order->getIncrementId(),
                'paymentTransactionId' => (string)($item['paymentTransactionId'] ?? ''),
                'price' => Amount::format($amount),
                'currency' => $currency,
            ], $storeId);
            if (($refund['status'] ?? '') !== 'success') {
                $error = trim(($refund['errorCode'] ?? '') . ' ' . ($refund['errorMessage'] ?? ''));
                return false;
            }
        }
        return true;
    }
}
