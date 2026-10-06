<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Api\Client;
use Softaware\IyzicoPayment\Model\Config;
use Softaware\IyzicoPayment\Model\PaymentInfo;
use Softaware\IyzicoPayment\Model\ResultType;
use Softaware\IyzicoPayment\Model\Transaction;
use Softaware\IyzicoPayment\Model\TransactionRepository;

/**
 * Cancelling an iyzico order that waits for payment (admin, API, Magento's pending order clean-up): stops when
 * iyzico already has a successful payment for it (or one still under way), so a paid order is never cancelled by
 * mistake. Unpaid orders cancel as usual; this module's own cancellations skip the check (already done).
 * (sales_order_payment_cancel runs before the order is marked cancelled.)
 */
class GuardPaidOrderCancel implements ObserverInterface
{
    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly Client $client,
        private readonly Config $config
    ) {
    }

    public function execute(Observer $observer): void
    {
        $payment = $observer->getData('payment');
        if (!$payment instanceof Payment
            || $payment->getMethod() !== Config::METHOD_CODE
            || $payment->getData(PaymentInfo::FLAG_SKIP_CANCEL_CHECK)
        ) {
            return;
        }
        $order = $payment->getOrder();
        if (!$order instanceof Order || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return;
        }
        $storeId = (int)$order->getStoreId();
        foreach ($this->transactions->getForOrder((int)$order->getId(), 5) as $transaction) {
            if ($transaction->getStatus() !== Transaction::STATUS_STARTED) {
                continue;
            }
            try {
                $result = $this->client->post(Client::PATH_CF_RETRIEVE, [
                    'locale' => $this->config->getLocale($storeId),
                    'conversationId' => (string)$order->getIncrementId(),
                    'token' => $transaction->getToken(),
                ], $storeId, true, 10);
            } catch (ApiException) {
                continue;
            }
            if (in_array(ResultType::of($result), [ResultType::PAID, ResultType::PENDING], true)) {
                throw new LocalizedException(__(
                    'iyzico has a payment for order %1 (or one still in progress). Use "Check Status at iyzico" on the order page instead of cancelling it.',
                    $order->getIncrementId()
                ));
            }
        }
    }
}
