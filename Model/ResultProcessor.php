<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use CommerceGears\IyzicoPayment\Model\Api\Signature;

/**
 * Applies an answer from iyzico (Checkout Form retrieve or payment detail, always fetched by us from the API,
 * never taken from the browser or a notification) to an order. Must run inside OrderLock.
 *
 * A payment is registered only when iyzico says SUCCESS and the basket ID, conversation ID, currency and amount
 * match what this store sent for this order (and the response signature is valid). Then the payment is captured
 * on an invoice (or held in payment review while iyzico's fraud team checks it) and the order e-mail is sent.
 */
class ResultProcessor
{
    public const OUTCOME_PAID = 'paid';
    public const OUTCOME_REVIEW = 'review';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_UNKNOWN = 'unknown';
    public const OUTCOME_ALREADY = 'already';
    public const OUTCOME_REVERSED = 'reversed';
    public const OUTCOME_MISMATCH = 'mismatch';

    private const REGISTRABLE_STATES = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT];

    public function __construct(
        private readonly Config $config,
        private readonly Signature $signature,
        private readonly TransactionRepository $transactions,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly Reverser $reverser,
        private readonly OrderCanceller $canceller,
        private readonly DateTime $dateTime,
        private readonly Log $log
    ) {
    }

    /**
     * @param string $responseType "cf_retrieve" or "payment_detail" (selects the signature fields)
     * @param bool $cancelOnFailure Cancel a pending order when iyzico says the payment failed
     */
    public function process(
        Order $order,
        ?Transaction $transaction,
        array $result,
        string $responseType,
        string $source,
        bool $cancelOnFailure = true
    ): string {
        $type = ResultType::of($result);
        $this->log->debug('Applying iyzico result', [
            'order' => $order->getIncrementId(), 'source' => $source, 'result' => $type,
            'paymentStatus' => $result['paymentStatus'] ?? null, 'errorCode' => $result['errorCode'] ?? null,
        ], (int)$order->getStoreId());

        if ($transaction) {
            $transaction->setData('checked_at', $this->dateTime->gmtDate());
        }
        switch ($type) {
            case ResultType::PAID:
                return $this->paid($order, $transaction, $result, $responseType, $source);
            case ResultType::FAILED:
                return $this->failed($order, $transaction, $result, $cancelOnFailure);
            case ResultType::PENDING:
                $transaction && $this->transactions->save($transaction);
                return self::OUTCOME_PENDING;
            default:
                $transaction && $this->transactions->save($transaction);
                return self::OUTCOME_UNKNOWN;
        }
    }

    private function failed(Order $order, ?Transaction $transaction, array $result, bool $cancel): string
    {
        $code = (string)($result['errorCode'] ?? ($result['paymentStatus'] ?? ''));
        $message = (string)($result['errorMessage'] ?? '');
        if (!isset($result['errorCode']) && isset($result['mdStatus']) && (string)$result['mdStatus'] !== '1') {
            // 3-D Secure failed at the bank (wrong code, cancelled): iyzico sends no error code, only mdStatus.
            $code = ErrorMessages::THREEDS_PREFIX . (string)$result['mdStatus'];
            $message = $message !== '' ? $message : '3-D Secure verification failed (mdStatus ' . $result['mdStatus'] . ')';
        }
        // Only a final answer (callback) marks the form as failed: a notification about one declined card can
        // arrive while the customer is still trying another card in the same form.
        if ($cancel && $transaction && $transaction->getStatus() === Transaction::STATUS_STARTED) {
            $transaction->setData('status', Transaction::STATUS_FAILED);
            $transaction->setData('error_code', mb_substr($code, 0, 40));
            $transaction->setData('error_message', mb_substr($message, 0, 255));
        }
        $transaction && $this->transactions->save($transaction);
        if ($cancel && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            $this->canceller->cancel($order, __('The iyzico payment was not completed (%1). The order was cancelled.', trim($code . ' ' . $message)));
        }
        return self::OUTCOME_FAILED;
    }

    private function paid(Order $order, ?Transaction $transaction, array $result, string $responseType, string $source): string
    {
        $storeId = (int)$order->getStoreId();
        $paymentId = (string)$result['paymentId'];
        $transaction ??= $this->transactions->getByPaymentId($paymentId) ?? $this->transactions->create([
            'order_id' => (int)$order->getId(),
            'increment_id' => (string)$order->getIncrementId(),
            'store_id' => $storeId,
            'environment' => $this->config->getEnvironment($storeId),
            'token' => 'payment-' . $paymentId,
            'amount' => (float)$order->getGrandTotal(),
            'currency' => (string)$order->getOrderCurrencyCode(),
            'status' => Transaction::STATUS_STARTED,
        ]);
        $this->fillTransaction($transaction, $result);

        $problem = $this->verify($order, $transaction, $result, $responseType);
        if ($problem !== null) {
            $transaction->setData('status', Transaction::STATUS_MISMATCH);
            $transaction->setData('error_message', mb_substr((string)$problem, 0, 255));
            $this->transactions->save($transaction);
            $payment = $order->getPayment();
            $payment->setAdditionalInformation(PaymentInfo::ATTENTION, (string)__(
                'iyzico payment %1 was not registered: %2',
                $paymentId,
                $problem
            ));
            $order->addCommentToStatusHistory(__(
                'iyzico reports payment %1 for this order, but it was not registered: %2 Please check it in the iyzico merchant panel.',
                $paymentId,
                $problem
            ));
            $this->orderRepository->save($order);
            $this->log->error('iyzico result does not match the order', [
                'order' => $order->getIncrementId(), 'paymentId' => $paymentId, 'problem' => (string)$problem,
                'source' => $source,
            ]);
            return self::OUTCOME_MISMATCH;
        }

        $fraudStatus = isset($result['fraudStatus']) ? (int)$result['fraudStatus'] : 1;
        $payment = $order->getPayment();
        $registered = (string)$payment->getAdditionalInformation(PaymentInfo::PAYMENT_ID);

        if ($registered === $paymentId) {
            $this->transactions->save($transaction);
            return self::OUTCOME_ALREADY;
        }
        if ($registered !== '' || in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, Order::STATE_CLOSED], true)) {
            $this->reverser->reverse($order, $transaction, $result, __('the order had already been paid with another payment.'));
            $this->orderRepository->save($order);
            return self::OUTCOME_REVERSED;
        }
        if ($order->getState() === Order::STATE_CANCELED) {
            $this->reverser->reverse($order, $transaction, $result, __('the order had been cancelled before the payment arrived.'));
            $this->orderRepository->save($order);
            return self::OUTCOME_REVERSED;
        }
        if ($fraudStatus === -1) {
            $transaction->setData('status', Transaction::STATUS_REJECTED);
            $this->transactions->save($transaction);
            if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                $this->canceller->cancel($order, __('iyzico\'s fraud check rejected payment %1; iyzico gives the money back to the customer. The order was cancelled.', $paymentId));
            }
            return self::OUTCOME_FAILED;
        }
        if (!in_array($order->getState(), self::REGISTRABLE_STATES, true)) {
            $problem = __('the order is in state "%1".', $order->getState());
            $transaction->setData('status', Transaction::STATUS_MISMATCH);
            $transaction->setData('error_message', mb_substr((string)$problem, 0, 255));
            $this->transactions->save($transaction);
            $payment->setAdditionalInformation(PaymentInfo::ATTENTION, (string)__('iyzico payment %1 was not registered: %2', $paymentId, $problem));
            $order->addCommentToStatusHistory(__('iyzico payment %1 arrived but was not registered: %2 Please check the order.', $paymentId, $problem));
            $this->orderRepository->save($order);
            return self::OUTCOME_MISMATCH;
        }

        $review = $fraudStatus === 0;
        $this->register($order, $transaction, $result, $review);
        return $review ? self::OUTCOME_REVIEW : self::OUTCOME_PAID;
    }

    /**
     * @return Phrase|null what does not match, or null when the result belongs to this order
     */
    private function verify(Order $order, Transaction $transaction, array $result, string $responseType): ?Phrase
    {
        $increment = (string)$order->getIncrementId();
        if ((string)($result['basketId'] ?? '') !== $increment) {
            return __('the basket ID is "%1", not the order number.', (string)($result['basketId'] ?? ''));
        }
        if ((string)($result['conversationId'] ?? $increment) !== $increment) {
            return __('the conversation ID does not match the order number.');
        }
        $currency = strtoupper((string)($result['currency'] ?? ''));
        if ($currency !== strtoupper((string)$order->getOrderCurrencyCode())) {
            return __('the currency is %1, the order is in %2.', $currency, $order->getOrderCurrencyCode());
        }
        $price = Amount::toMinor($result['price'] ?? 0);
        $orderTotal = Amount::toMinor($order->getGrandTotal());
        $sent = Amount::toMinor($transaction->getData('amount'));
        if ($price !== $orderTotal || $price !== $sent) {
            return __('the amount is %1, the order total is %2.', Amount::format($result['price'] ?? 0), Amount::format($order->getGrandTotal()));
        }
        if (Amount::toMinor($result['paidPrice'] ?? 0) < $price) {
            return __('the amount charged (%1) is less than the order total.', Amount::format($result['paidPrice'] ?? 0));
        }
        if (!$this->signature->isResponseValid($responseType, $result, $this->config->getSecretKey((int)$order->getStoreId()))) {
            return __('the response signature is not valid.');
        }
        return null;
    }

    private function register(Order $order, Transaction $transaction, array $result, bool $review): void
    {
        $storeId = (int)$order->getStoreId();
        $paymentId = (string)$result['paymentId'];
        $currency = (string)$result['currency'];
        $price = (float)$result['price'];
        $paidPrice = (float)$result['paidPrice'];
        $installment = max(1, (int)($result['installment'] ?? 1));

        $items = [];
        foreach ((array)($result['itemTransactions'] ?? []) as $item) {
            $items[] = [
                'id' => (string)($item['itemId'] ?? ''),
                'ptx' => (string)($item['paymentTransactionId'] ?? ''),
                'price' => round((float)($item['price'] ?? 0), 2),
                'paid' => round((float)($item['paidPrice'] ?? 0), 2),
                'refunded' => 0.0,
            ];
        }

        $payment = $order->getPayment();
        $payment->setAdditionalInformation(PaymentInfo::PAYMENT_ID, $paymentId);
        $payment->setAdditionalInformation(PaymentInfo::TOKEN, (string)($result['token'] ?? $transaction->getToken()));
        $payment->setAdditionalInformation(PaymentInfo::ENVIRONMENT, (string)$transaction->getData('environment'));
        $payment->setAdditionalInformation(PaymentInfo::PRICE, Amount::format($price));
        $payment->setAdditionalInformation(PaymentInfo::PAID_PRICE, Amount::format($paidPrice));
        $payment->setAdditionalInformation(PaymentInfo::CURRENCY, $currency);
        $payment->setAdditionalInformation(PaymentInfo::INSTALLMENT, $installment);
        $payment->setAdditionalInformation(PaymentInfo::FRAUD_STATUS, $review ? 0 : 1);
        $payment->setAdditionalInformation(PaymentInfo::CARD_ASSOCIATION, (string)($result['cardAssociation'] ?? ''));
        $payment->setAdditionalInformation(PaymentInfo::CARD_FAMILY, (string)($result['cardFamily'] ?? ''));
        $payment->setAdditionalInformation(PaymentInfo::CARD_TYPE, (string)($result['cardType'] ?? ''));
        $payment->setAdditionalInformation(PaymentInfo::BIN, substr((string)($result['binNumber'] ?? ''), 0, 8));
        $payment->setAdditionalInformation(PaymentInfo::LAST_FOUR, substr((string)($result['lastFourDigits'] ?? ''), -4));
        $payment->setAdditionalInformation(PaymentInfo::MERCHANT_COMMISSION, Amount::format($result['merchantCommissionRateAmount'] ?? 0));
        $payment->setAdditionalInformation(
            PaymentInfo::IYZICO_FEE,
            Amount::format((float)($result['iyziCommissionFee'] ?? 0) + (float)($result['iyziCommissionRateAmount'] ?? 0))
        );
        $payment->setAdditionalInformation(PaymentInfo::PAID_AT, $this->dateTime->gmtDate());
        $payment->setAdditionalInformation(PaymentInfo::ITEMS, $items);
        $payment->setAdditionalInformation(PaymentInfo::REFUNDS, []);
        $payment->unsAdditionalInformation(PaymentInfo::ATTENTION);
        $payment->setCcLast4(substr((string)($result['lastFourDigits'] ?? ''), -4));
        $payment->setCcType((string)($result['cardAssociation'] ?? ''));

        $payment->setTransactionId($paymentId);
        $payment->setIsTransactionClosed(false);
        $payment->setTransactionAdditionalInfo(
            \Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS,
            array_filter([
                'paymentId' => $paymentId,
                'installment' => (string)$installment,
                'paidPrice' => Amount::format($paidPrice) . ' ' . $currency,
                'card' => trim(($result['cardAssociation'] ?? '') . ' ' . ($result['cardFamily'] ?? '') . ' ' . ($result['lastFourDigits'] ?? '')),
                'fraudStatus' => (string)($result['fraudStatus'] ?? ''),
                'authCode' => (string)($result['authCode'] ?? ''),
            ], 'strlen')
        );
        if ($review) {
            $payment->setIsTransactionPending(true);
        }
        $payment->registerCaptureNotification((float)$order->getBaseGrandTotal(), true);

        if ($review) {
            $status = $this->config->getReviewStatus($storeId);
            if (array_key_exists($status, $order->getConfig()->getStateStatuses(Order::STATE_PAYMENT_REVIEW))) {
                $order->setStatus($status);
            }
            $order->addCommentToStatusHistory(__(
                'iyzico is reviewing payment %1 (fraud check). Do not ship until iyzico approves it; the order is updated automatically.',
                $paymentId
            ));
        }
        if (Amount::toMinor($paidPrice) > Amount::toMinor($price)) {
            $order->addCommentToStatusHistory(__(
                'The customer paid %1 %2 in %3 installments. The installment charge of %4 %2 is on top of the order total and is not part of the Magento totals; refunds give it back in proportion.',
                Amount::format($paidPrice),
                $currency,
                $installment,
                Amount::format($paidPrice - $price)
            ));
        } elseif ($installment > 1) {
            $order->addCommentToStatusHistory(__('Paid in %1 installments.', $installment));
        }
        $this->orderRepository->save($order);

        $transaction->setData('status', $review ? Transaction::STATUS_REVIEW : Transaction::STATUS_PAID);
        $this->transactions->save($transaction);

        if (!$review) {
            $this->sendOrderEmail($order);
        }
        $this->log->info('Payment registered', [
            'order' => $order->getIncrementId(), 'paymentId' => $paymentId, 'review' => $review,
        ]);
    }

    public function sendOrderEmail(Order $order): void
    {
        if ($order->getEmailSent()) {
            return;
        }
        try {
            $order->setCanSendNewEmailFlag(true);
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->log->error('Order e-mail could not be sent', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
        }
    }

    private function fillTransaction(Transaction $transaction, array $result): void
    {
        $transaction->addData([
            'payment_id' => (string)$result['paymentId'],
            'paid_amount' => (float)($result['paidPrice'] ?? 0),
            'installment' => (int)($result['installment'] ?? 1) ?: 1,
            'fraud_status' => isset($result['fraudStatus']) ? (int)$result['fraudStatus'] : null,
            'card_association' => mb_substr((string)($result['cardAssociation'] ?? ''), 0, 40) ?: null,
            'card_family' => mb_substr((string)($result['cardFamily'] ?? ''), 0, 60) ?: null,
            'card_type' => mb_substr((string)($result['cardType'] ?? ''), 0, 40) ?: null,
            'bin_number' => substr((string)($result['binNumber'] ?? ''), 0, 8) ?: null,
            'last_four' => substr((string)($result['lastFourDigits'] ?? ''), -4) ?: null,
            'error_code' => null,
            'error_message' => null,
        ]);
    }
}
