<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;

/**
 * Order placement: the order waits in "Pending Payment" (stock reserved, no invoice, no order e-mail) until iyzico
 * confirms the payment.
 */
class InitializeCommand implements CommandInterface
{
    public function execute(array $commandSubject)
    {
        $stateObject = SubjectReader::readStateObject($commandSubject);
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $order->getConfig()->getStateDefaultStatus(Order::STATE_PENDING_PAYMENT));
        $stateObject->setData('is_notified', false);
        return null;
    }
}
