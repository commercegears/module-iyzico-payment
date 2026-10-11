<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use CommerceGears\IyzicoPayment\Model\Amount;
use CommerceGears\IyzicoPayment\Model\Api\ApiException;
use CommerceGears\IyzicoPayment\Model\Api\Client;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\Log;
use CommerceGears\IyzicoPayment\Model\PaymentInfo;
use CommerceGears\IyzicoPayment\Model\TransactionRepository;

/**
 * Online refund from a credit memo (full, partial, several partial refunds; shipping and adjustments included).
 *
 * iyzico refunds per basket item (paymentTransactionId) and never more than an item's remaining paid amount, so the
 * credit memo total is spread over the stored items. When the customer paid an installment charge on top of the
 * order total, refunds give it back in proportion and the last refund of the order returns everything that is left.
 * A full refund on the day of the payment uses iyzico's cancel (nothing appears on the card statement).
 * Nothing is recorded as refunded unless iyzico confirmed it.
 */
class RefundCommand implements CommandInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
        private readonly TransactionRepository $transactions,
        private readonly DateTime $dateTime,
        private readonly Log $log
    ) {
    }

    public function execute(array $commandSubject)
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $baseAmount = (float)SubjectReader::readAmount($commandSubject);
        /** @var Order $order */
        $order = $payment->getOrder();
        $paymentId = (string)$payment->getAdditionalInformation(PaymentInfo::PAYMENT_ID);
        $items = (array)$payment->getAdditionalInformation(PaymentInfo::ITEMS);
        if ($paymentId === '' || !$items) {
            throw new LocalizedException(__('This order has no iyzico payment details. Refund it in the iyzico merchant panel and create an offline credit memo.'));
        }
        $currency = (string)($payment->getAdditionalInformation(PaymentInfo::CURRENCY) ?: $order->getOrderCurrencyCode());
        $creditmemo = $payment->getCreditmemo();
        $amount = $creditmemo ? (float)$creditmemo->getGrandTotal() : $baseAmount * (float)($order->getBaseToOrderRate() ?: 1);
        $amountMinor = Amount::toMinor($amount);
        if ($amountMinor <= 0) {
            throw new LocalizedException(__('The refund amount must be greater than zero.'));
        }

        $paidMinor = array_sum(array_map(static fn($i) => Amount::toMinor($i['paid'] ?? 0), $items));
        $refundedMinor = array_sum(array_map(static fn($i) => Amount::toMinor($i['refunded'] ?? 0), $items));
        $remainingMinor = $paidMinor - $refundedMinor;
        $orderTotalMinor = Amount::toMinor($order->getGrandTotal());
        $refunds = (array)$payment->getAdditionalInformation(PaymentInfo::REFUNDS);
        $refundedOrderMinor = array_sum(array_map(static fn($r) => Amount::toMinor($r['order_amount'] ?? 0), $refunds));

        // Order-currency credit memo amount -> amount to give back at iyzico (incl. a share of any installment charge).
        $targetMinor = $orderTotalMinor > 0 ? (int)round($amountMinor * $paidMinor / $orderTotalMinor) : $amountMinor;
        if ($refundedOrderMinor + $amountMinor >= $orderTotalMinor || $targetMinor > $remainingMinor) {
            if ($targetMinor > $remainingMinor + 1 && $refundedOrderMinor + $amountMinor < $orderTotalMinor) {
                throw new LocalizedException(__(
                    'iyzico can refund at most %1 %2 for this order.',
                    Amount::format(Amount::fromMinor($remainingMinor)),
                    $currency
                ));
            }
            $targetMinor = $remainingMinor;
        }
        if ($targetMinor <= 0) {
            throw new LocalizedException(__('Everything paid with iyzico for this order has already been refunded.'));
        }

        $refundType = 'refund';
        if ($refundedMinor === 0 && $targetMinor === $paidMinor && $this->isPaymentDay($payment)) {
            if ($this->cancelPayment($order, $paymentId)) {
                $refundType = 'cancel';
                foreach ($items as $i => $item) {
                    $items[$i]['refunded'] = $item['paid'];
                }
            }
        }
        if ($refundType === 'refund') {
            $items = $this->refundItems($order, $items, $targetMinor, $currency);
        }

        $refunds[] = [
            'at' => $this->dateTime->gmtDate(),
            'amount' => Amount::format(Amount::fromMinor($targetMinor)),
            'order_amount' => Amount::format($amount),
            'type' => $refundType,
        ];
        $payment->setAdditionalInformation(PaymentInfo::ITEMS, $items);
        $payment->setAdditionalInformation(PaymentInfo::REFUNDS, $refunds);
        $payment->setTransactionId($paymentId . '-' . $refundType . '-' . count($refunds));
        $payment->setIsTransactionClosed(true);
        $fullyRefunded = $refundedMinor + $targetMinor >= $paidMinor;
        $payment->setShouldCloseParentTransaction($fullyRefunded);
        $payment->setMessage(__(
            'iyzico %1: %2 %3 given back to the customer.',
            $refundType === 'cancel' ? __('cancelled the payment') : __('refund'),
            Amount::format(Amount::fromMinor($targetMinor)),
            $currency
        ));

        $transaction = $this->transactions->getByPaymentId($paymentId);
        if ($transaction) {
            $transaction->setData('refunded_amount', Amount::fromMinor($refundedMinor + $targetMinor));
            $this->transactions->save($transaction);
        }
        $this->log->info('Refund done', [
            'order' => $order->getIncrementId(), 'paymentId' => $paymentId, 'type' => $refundType,
            'amount' => Amount::format(Amount::fromMinor($targetMinor)),
        ]);
        return null;
    }

    /**
     * Refunds $targetMinor over the items, never more than an item's remaining paid amount.
     *
     * @throws LocalizedException
     */
    private function refundItems(Order $order, array $items, int $targetMinor, string $currency): array
    {
        $storeId = (int)$order->getStoreId();
        $leftMinor = $targetMinor;
        $doneMinor = 0;
        foreach ($items as $i => $item) {
            if ($leftMinor <= 0) {
                break;
            }
            $capacity = Amount::toMinor($item['paid'] ?? 0) - Amount::toMinor($item['refunded'] ?? 0);
            if ($capacity <= 0 || (string)($item['ptx'] ?? '') === '') {
                continue;
            }
            $partMinor = min($capacity, $leftMinor);
            try {
                $response = $this->client->post(Client::PATH_REFUND, [
                    'locale' => $this->config->getLocale($storeId),
                    'conversationId' => (string)$order->getIncrementId(),
                    'paymentTransactionId' => (string)$item['ptx'],
                    'price' => Amount::format(Amount::fromMinor($partMinor)),
                    'currency' => $currency,
                ], $storeId);
            } catch (ApiException $e) {
                $this->failPartway($order, $doneMinor, $currency, $e->getMessage());
            }
            if (($response['status'] ?? '') !== 'success') {
                $code = (string)($response['errorCode'] ?? '');
                $this->failPartway($order, $doneMinor, $currency, trim($code . ' ' . ($response['errorMessage'] ?? '')));
            }
            $items[$i]['refunded'] = Amount::fromMinor(Amount::toMinor($item['refunded'] ?? 0) + $partMinor);
            $leftMinor -= $partMinor;
            $doneMinor += $partMinor;
        }
        if ($leftMinor > 0) {
            $this->failPartway($order, $doneMinor, $currency, (string)__('not enough refundable amount left on the payment'));
        }
        return $items;
    }

    /**
     * @throws LocalizedException always
     */
    private function failPartway(Order $order, int $doneMinor, string $currency, string $reason): never
    {
        $this->log->error('Refund failed', [
            'order' => $order->getIncrementId(), 'alreadyRefunded' => Amount::format(Amount::fromMinor($doneMinor)),
            'reason' => $reason,
        ]);
        if ($doneMinor > 0) {
            throw new LocalizedException(__(
                'iyzico refunded %1 %2 and then stopped with: %3. The credit memo was not saved. Record the %1 %2 with an offline credit memo, then refund the rest.',
                Amount::format(Amount::fromMinor($doneMinor)),
                $currency,
                $reason
            ));
        }
        throw new LocalizedException(__('iyzico did not accept the refund: %1', $reason));
    }

    private function cancelPayment(Order $order, string $paymentId): bool
    {
        $storeId = (int)$order->getStoreId();
        try {
            $response = $this->client->post(Client::PATH_CANCEL, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => (string)$order->getIncrementId(),
                'paymentId' => $paymentId,
            ], $storeId);
        } catch (ApiException $e) {
            // Outcome unknown: do not fall back to a refund (that could pay the customer twice).
            throw new LocalizedException(__('iyzico could not be reached. Nothing was refunded; please try again.'));
        }
        if (($response['status'] ?? '') === 'success') {
            return true;
        }
        $this->log->info('Cancel not possible, refunding instead', [
            'order' => $order->getIncrementId(), 'errorCode' => $response['errorCode'] ?? null,
        ]);
        return false;
    }

    /**
     * Same calendar day in Türkiye as the payment (banks settle at the end of the day).
     */
    private function isPaymentDay(Payment $payment): bool
    {
        $paidAt = (string)$payment->getAdditionalInformation(PaymentInfo::PAID_AT);
        if ($paidAt === '') {
            return false;
        }
        $zone = new \DateTimeZone('Europe/Istanbul');
        $paidDay = (new \DateTime($paidAt, new \DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
        return $paidDay === (new \DateTime('now', $zone))->format('Y-m-d');
    }
}
