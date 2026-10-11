<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\LockBusyException;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\ResultProcessor;
use CommerceGears\IyzicoPayment\Model\StatusChecker;

/**
 * "Check Status at iyzico" on the order page (POST + form key): asks iyzico now and updates the order the same way
 * the callback would. Never cancels the order; the admin decides.
 */
class CheckStatus extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'CommerceGears_IyzicoPayment::check_status';

    public function __construct(
        Context $context,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderStatusService $statusService
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $redirect = $this->resultRedirectFactory->create();
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Exception) {
            $this->messageManager->addErrorMessage(__('This order no longer exists.'));
            return $redirect->setPath('sales/order/index');
        }
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);
        if ($order->getPayment()?->getMethod() !== Config::METHOD_CODE) {
            $this->messageManager->addErrorMessage(__('This order was not paid with iyzico.'));
            return $redirect;
        }
        try {
            $outcome = $this->statusService->check($orderId, StatusChecker::SOURCE_ADMIN, false);
        } catch (LockBusyException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect;
        }
        match ($outcome) {
            ResultProcessor::OUTCOME_PAID => $this->messageManager->addSuccessMessage(__('iyzico confirmed the payment. The order has been invoiced.')),
            ResultProcessor::OUTCOME_REVIEW => $this->messageManager->addNoticeMessage(__('iyzico is still reviewing this payment. The order is updated automatically when iyzico decides.')),
            ResultProcessor::OUTCOME_PENDING => $this->messageManager->addNoticeMessage(__('The payment is still under way at iyzico (for example a bank transfer). Please check again later.')),
            ResultProcessor::OUTCOME_FAILED => $this->messageManager->addNoticeMessage(__('iyzico has no successful payment for this order. If the customer will not pay, you can cancel it.')),
            ResultProcessor::OUTCOME_REVERSED => $this->messageManager->addNoticeMessage(__('iyzico has a payment for this order although it was already paid or cancelled. See the order comments.')),
            ResultProcessor::OUTCOME_MISMATCH => $this->messageManager->addErrorMessage(__('iyzico reports a payment that does not match this order. See the order comments and the iyzico merchant panel.')),
            ResultProcessor::OUTCOME_ALREADY => $this->messageManager->addSuccessMessage(__('The order is up to date with iyzico.')),
            default => $this->messageManager->addErrorMessage(__('iyzico did not give a clear answer. Please try again in a moment.')),
        };
        return $redirect;
    }
}
