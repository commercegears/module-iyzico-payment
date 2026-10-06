<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Block;

use Magento\Framework\App\Area;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as BaseInfo;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Model\PaymentInfo;

/**
 * Payment information: card, installments and payment ID for customers (account, e-mails, PDFs); the full iyzico
 * panel with fees, refunds and the "Check status at iyzico" button in the admin.
 */
class Info extends BaseInfo
{
    public function __construct(
        Context $context,
        private readonly AuthorizationInterface $authorization,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * The admin panel in the admin; Magento's default payment info everywhere else (e-mails set the area to
     * frontend and secure mode, PDFs use their own template).
     */
    public function getTemplate()
    {
        $template = parent::getTemplate();
        if ($template === 'Magento_Payment::info/default.phtml'
            && !$this->getIsSecureMode()
            && $this->getArea() === Area::AREA_ADMINHTML
        ) {
            return 'Softaware_IyzicoPayment::info/admin.phtml';
        }
        return $template;
    }

    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }
        $transport = parent::_prepareSpecificInformation($transport);
        $data = [];
        $card = $this->getCardText();
        if ($card !== '') {
            $data[(string)__('Card')] = $card;
        }
        $installment = (int)$this->getValue(PaymentInfo::INSTALLMENT);
        if ($installment > 1) {
            $data[(string)__('Installments')] = (string)$installment;
        }
        $paymentId = $this->getValue(PaymentInfo::PAYMENT_ID);
        if ($paymentId !== '') {
            $data[(string)__('iyzico Payment ID')] = $paymentId;
        }
        return $transport->addData($data);
    }

    public function getValue(string $key): string
    {
        $value = $this->getInfo()->getAdditionalInformation($key);
        return is_scalar($value) ? (string)$value : '';
    }

    public function getList(string $key): array
    {
        $value = $this->getInfo()->getAdditionalInformation($key);
        return is_array($value) ? $value : [];
    }

    public function getCardText(): string
    {
        $last4 = $this->getValue(PaymentInfo::LAST_FOUR);
        $parts = array_filter([
            str_replace('_', ' ', $this->getValue(PaymentInfo::CARD_ASSOCIATION)),
            $this->getValue(PaymentInfo::CARD_FAMILY),
            $last4 !== '' ? '•••• ' . $last4 : '',
        ]);
        return implode(' ', $parts);
    }

    public function getOrder(): ?Order
    {
        $order = $this->getInfo()->getOrder();
        return $order instanceof Order ? $order : null;
    }

    public function getStatusLabel(): string
    {
        $order = $this->getOrder();
        $paymentId = $this->getValue(PaymentInfo::PAYMENT_ID);
        $fraud = $this->getValue(PaymentInfo::FRAUD_STATUS);
        if ($order && $order->getState() === Order::STATE_PAYMENT_REVIEW) {
            return (string)__('Paid, held for iyzico\'s fraud review');
        }
        if ($paymentId !== '') {
            if ($fraud === '-1') {
                return (string)__('Rejected by iyzico\'s fraud review');
            }
            $refunds = $this->getList(PaymentInfo::REFUNDS);
            return $refunds ? (string)__('Paid, refunds made') : (string)__('Paid');
        }
        if ($order && in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true)) {
            return (string)__('Waiting for payment');
        }
        return (string)__('Not paid');
    }

    public function canCheckStatus(): bool
    {
        $order = $this->getOrder();
        return $order
            && in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW, Order::STATE_PAYMENT_REVIEW], true)
            && $this->authorization->isAllowed('Softaware_IyzicoPayment::check_status');
    }

    public function getCheckStatusUrl(): string
    {
        return $this->getUrl('softaware_iyzico/order/checkStatus', ['order_id' => (int)$this->getOrder()?->getId()]);
    }

    public function getTransactionsUrl(): string
    {
        return $this->getUrl('softaware_iyzico/transaction/index');
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    public function isSandbox(): bool
    {
        return $this->getValue(PaymentInfo::ENVIRONMENT) === 'sandbox';
    }

    public function getCurrency(): string
    {
        return $this->getValue(PaymentInfo::CURRENCY) ?: (string)$this->getOrder()?->getOrderCurrencyCode();
    }

    public function toPdf()
    {
        $this->setTemplate('Magento_Payment::info/pdf/default.phtml');
        return $this->toHtml();
    }
}
