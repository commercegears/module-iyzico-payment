<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use CommerceGears\IyzicoPayment\Model\Api\ApiException;
use CommerceGears\IyzicoPayment\Model\Api\Client;
use CommerceGears\IyzicoPayment\Model\Api\Signature;

/**
 * Asks iyzico about an order and applies the answer (callback, webhook, cron, customer back in the basket, admin
 * "Check status at iyzico"). Every method expects to run inside OrderLock on a freshly loaded order.
 *
 * An order is only ever cancelled after iyzico answered for every payment form of the order and for the order
 * number itself, and none of them is paid or still under way.
 */
class StatusChecker
{
    public const SOURCE_CALLBACK = 'callback';
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_CRON = 'cron';
    public const SOURCE_CUSTOMER = 'customer';
    public const SOURCE_ADMIN = 'admin';

    /** Bank transfer / shopping credit can stay open for days (docs: bank transfer time limit 5 days). */
    private const MAX_PENDING_DAYS = 8;

    /** 3-D Secure verification takes minutes; a payment still waiting for it after this long was abandoned. */
    private const MAX_THREEDS_HOURS = 3;
    private const THREEDS_STATUSES = ['INIT_THREEDS', 'CALLBACK_THREEDS'];

    private const FINAL_OUTCOMES = [
        ResultProcessor::OUTCOME_PAID, ResultProcessor::OUTCOME_REVIEW, ResultProcessor::OUTCOME_ALREADY,
        ResultProcessor::OUTCOME_REVERSED, ResultProcessor::OUTCOME_MISMATCH,
    ];

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
        private readonly Signature $signature,
        private readonly TransactionRepository $transactions,
        private readonly ResultProcessor $processor,
        private readonly OrderCanceller $canceller,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Log $log
    ) {
    }

    /**
     * Result of one payment form (by token).
     */
    public function checkToken(
        Order $order,
        Transaction $transaction,
        string $source,
        bool $cancelOnFailure,
        ?int $timeout = null
    ): string {
        $storeId = (int)$order->getStoreId();
        try {
            $result = $this->client->post(Client::PATH_CF_RETRIEVE, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => (string)$order->getIncrementId(),
                'token' => $transaction->getToken(),
            ], $storeId, true, $timeout);
        } catch (ApiException $e) {
            return ResultProcessor::OUTCOME_UNKNOWN;
        }
        return $this->processor->process($order, $transaction, $result, 'cf_retrieve', $source, $cancelOnFailure);
    }

    /**
     * Complete check of an order waiting for payment or in payment review.
     *
     * @param bool $mayCancel Cancel the order when iyzico has no payment for it
     */
    public function check(Order $order, string $source, bool $mayCancel, ?int $timeout = null): string
    {
        if ($order->getState() === Order::STATE_PAYMENT_REVIEW) {
            return $this->checkReview($order, $source);
        }
        if ($order->getState() !== Order::STATE_PENDING_PAYMENT && $order->getState() !== Order::STATE_NEW) {
            return ResultProcessor::OUTCOME_ALREADY;
        }

        $outcomes = [];
        $open = [];
        foreach ($this->transactions->getForOrder((int)$order->getId(), 10) as $transaction) {
            if ($transaction->getStatus() !== Transaction::STATUS_STARTED) {
                continue;
            }
            $open[] = $transaction;
            $outcome = $this->checkToken($order, $transaction, $source, false, $timeout);
            if (in_array($outcome, self::FINAL_OUTCOMES, true)) {
                return $outcome;
            }
            $outcomes[] = $outcome;
        }

        if (in_array(ResultProcessor::OUTCOME_PENDING, $outcomes, true)) {
            $age = time() - strtotime((string)$order->getCreatedAt() . ' UTC');
            if (!$mayCancel || $age < self::MAX_PENDING_DAYS * 86400) {
                return ResultProcessor::OUTCOME_PENDING;
            }
        }
        if (in_array(ResultProcessor::OUTCOME_UNKNOWN, $outcomes, true)) {
            return ResultProcessor::OUTCOME_UNKNOWN;
        }
        if ((string)$order->getPayment()->getAdditionalInformation(PaymentInfo::ATTENTION) !== '') {
            return ResultProcessor::OUTCOME_MISMATCH;
        }

        // Safety net for a payment form whose token never reached us (e.g. a time-out while opening it).
        $byOrderNumber = $this->detailByConversation($order, $timeout);
        if ($byOrderNumber === null) {
            return ResultProcessor::OUTCOME_UNKNOWN;
        }
        if (ResultType::of($byOrderNumber) === ResultType::PAID) {
            return $this->processor->process($order, null, $byOrderNumber, 'payment_detail', $source, false);
        }
        $abandonedThreeDs = false;
        if (ResultType::of($byOrderNumber) === ResultType::PENDING) {
            // A card payment stuck in 3-D Secure (the customer closed the bank's verification page) stays in that
            // state at iyzico for good and no money was taken; after a few hours it is treated as not paid.
            $abandonedThreeDs = $mayCancel && $this->isAbandonedThreeDs($order, $byOrderNumber);
            if (!$abandonedThreeDs) {
                return ResultProcessor::OUTCOME_PENDING;
            }
        }
        if (!$mayCancel) {
            return ResultProcessor::OUTCOME_FAILED;
        }
        // The payment forms of the order were not paid: show them as failed in the transactions grid, not "opened".
        foreach ($open as $transaction) {
            if ($transaction->getStatus() === Transaction::STATUS_STARTED) {
                $transaction->addData([
                    'status' => Transaction::STATUS_FAILED,
                    'error_code' => $abandonedThreeDs ? 'NOT_PAID_3DS' : 'NOT_PAID',
                    'error_message' => 'No payment at iyzico; the order was cancelled.',
                ]);
                $this->transactions->save($transaction);
            }
        }
        $this->canceller->cancel($order, match (true) {
            $abandonedThreeDs => __('The customer did not complete the 3-D Secure verification, so iyzico took no payment. The unpaid order was cancelled.'),
            $source === StatusChecker::SOURCE_CUSTOMER => __('The customer left the iyzico payment page without paying. iyzico has no payment for this order, so it was cancelled.'),
            default => __('iyzico has no payment for this order. The unpaid order was cancelled.'),
        });
        $this->log->info('Unpaid order cancelled', ['order' => $order->getIncrementId(), 'source' => $source]);
        return ResultProcessor::OUTCOME_FAILED;
    }

    /**
     * Payment held for iyzico's fraud review: approve or cancel the order when iyzico has decided.
     */
    public function checkReview(Order $order, string $source): string
    {
        $payment = $order->getPayment();
        $paymentId = (string)$payment->getAdditionalInformation(PaymentInfo::PAYMENT_ID);
        if ($paymentId === '' || $order->getState() !== Order::STATE_PAYMENT_REVIEW) {
            return ResultProcessor::OUTCOME_ALREADY;
        }
        $storeId = (int)$order->getStoreId();
        try {
            $detail = $this->client->post(Client::PATH_PAYMENT_DETAIL, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => (string)$order->getIncrementId(),
                'paymentId' => $paymentId,
            ], $storeId, true);
        } catch (ApiException) {
            return ResultProcessor::OUTCOME_UNKNOWN;
        }
        if (($detail['status'] ?? '') !== 'success'
            || (string)($detail['paymentId'] ?? '') !== $paymentId
            || (string)($detail['basketId'] ?? '') !== (string)$order->getIncrementId()
            || !$this->signature->isResponseValid('payment_detail', $detail, $this->config->getSecretKey($storeId))
        ) {
            $this->log->error('Payment review check gave no usable answer', [
                'order' => $order->getIncrementId(), 'errorCode' => $detail['errorCode'] ?? null,
            ]);
            return ResultProcessor::OUTCOME_UNKNOWN;
        }

        $fraudStatus = (int)($detail['fraudStatus'] ?? 0);
        $transaction = $this->transactions->getByPaymentId($paymentId);
        if ($fraudStatus === 1 || $fraudStatus === 2) {
            $payment->setTransactionId($paymentId);
            $payment->setIsTransactionApproved(true);
            $payment->setAdditionalInformation(PaymentInfo::FRAUD_STATUS, 1);
            $payment->update(false);
            $order->addCommentToStatusHistory(__('iyzico approved payment %1 after its review. The order can be shipped.', $paymentId));
            $this->orderRepository->save($order);
            $this->processor->sendOrderEmail($order);
            if ($transaction) {
                $transaction->addData(['status' => Transaction::STATUS_PAID, 'fraud_status' => 1]);
                $this->transactions->save($transaction);
            }
            $this->log->info('Payment approved after review', ['order' => $order->getIncrementId(), 'source' => $source]);
            return ResultProcessor::OUTCOME_PAID;
        }
        if ($fraudStatus === -1) {
            $payment->setTransactionId($paymentId);
            $payment->setIsTransactionDenied(true);
            $payment->setAdditionalInformation(PaymentInfo::FRAUD_STATUS, -1);
            $payment->setData(PaymentInfo::FLAG_SKIP_CANCEL_CHECK, true);
            $payment->update(false);
            $order->addCommentToStatusHistory(__('iyzico rejected payment %1 after its review and gives the money back to the customer. The order was cancelled.', $paymentId));
            $this->orderRepository->save($order);
            if ($transaction) {
                $transaction->addData(['status' => Transaction::STATUS_REJECTED, 'fraud_status' => -1]);
                $this->transactions->save($transaction);
            }
            $this->log->info('Payment rejected after review', ['order' => $order->getIncrementId(), 'source' => $source]);
            return ResultProcessor::OUTCOME_FAILED;
        }
        return ResultProcessor::OUTCOME_REVIEW;
    }

    /**
     * True when iyzico's latest payment for the order is still waiting for 3-D Secure long after the order was placed.
     */
    private function isAbandonedThreeDs(Order $order, array $detail): bool
    {
        if (!in_array(strtoupper((string)($detail['paymentStatus'] ?? '')), self::THREEDS_STATUSES, true)) {
            return false;
        }
        $age = time() - strtotime((string)$order->getCreatedAt() . ' UTC');
        return $age >= self::MAX_THREEDS_HOURS * 3600;
    }

    /**
     * Payment detail by order number. Null when iyzico gave no clear answer.
     */
    private function detailByConversation(Order $order, ?int $timeout): ?array
    {
        $storeId = (int)$order->getStoreId();
        try {
            $detail = $this->client->post(Client::PATH_PAYMENT_DETAIL, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => (string)$order->getIncrementId(),
                'paymentConversationId' => (string)$order->getIncrementId(),
            ], $storeId, true, $timeout);
        } catch (ApiException) {
            return null;
        }
        return ResultType::of($detail) === ResultType::UNKNOWN && ($detail['status'] ?? '') !== 'success'
            ? null
            : $detail;
    }
}
