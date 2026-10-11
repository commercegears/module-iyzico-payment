<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use CommerceGears\IyzicoPayment\Model\Log;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\OrderToken;
use CommerceGears\IyzicoPayment\Model\ResultProcessor;
use CommerceGears\IyzicoPayment\Model\StatusChecker;
use CommerceGears\IyzicoPayment\Model\UrlProvider;

/**
 * "Cancel payment and return to the basket" on the payment page (POST with form key). The order is cancelled only
 * after iyzico confirms there is no payment for it; a paid order goes to the success page instead.
 */
class Cancel implements HttpPostActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OrderResolver $orderResolver,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderStatusService $statusService,
        private readonly UrlProvider $urlProvider,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Log $log
    ) {
    }

    public function execute(): ResultInterface
    {
        $order = $this->orderResolver->resolve($this->request, 't', OrderToken::PURPOSE_PAY);
        if (!$order) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        try {
            $outcome = $this->statusService->check((int)$order->getId(), StatusChecker::SOURCE_CUSTOMER, true, 10);
        } catch (\Throwable $e) {
            $this->log->error('Cancel from the payment page failed', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            $outcome = ResultProcessor::OUTCOME_UNKNOWN;
        }
        if ($outcome === ResultProcessor::OUTCOME_FAILED) {
            if ($this->orderResolver->isSessionOrder($order)) {
                $this->checkoutSession->restoreQuote();
            }
            $this->messageManager->addNoticeMessage(__('The payment was cancelled. Your basket has been restored, so you can try again or choose another payment method.'));
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        if (in_array($outcome, [ResultProcessor::OUTCOME_UNKNOWN, ResultProcessor::OUTCOME_PENDING], true)) {
            $this->messageManager->addErrorMessage(__('We could not confirm the payment status with iyzico. Please try again in a moment.'));
        }
        return $this->redirectFactory->create()->setUrl(
            $this->urlProvider->getResultUrl((int)$order->getStoreId(), (int)$order->getId())
        );
    }
}
