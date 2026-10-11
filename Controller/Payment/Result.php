<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use CommerceGears\IyzicoPayment\Model\ErrorMessages;
use CommerceGears\IyzicoPayment\Model\Log;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\OrderToken;
use CommerceGears\IyzicoPayment\Model\PageState;
use CommerceGears\IyzicoPayment\Model\StatusChecker;
use CommerceGears\IyzicoPayment\Model\Transaction;
use CommerceGears\IyzicoPayment\Model\TransactionRepository;
use CommerceGears\IyzicoPayment\Model\UrlProvider;

/**
 * Where the customer lands after iyzico (GET, with the visitor's own session again).
 *
 * The visitor whose checkout session placed the order goes on to Magento's success page, or back to a restored
 * basket with a clear message. Anyone else holding the signed link (valid 2 hours) only sees the order number and
 * whether the payment went through.
 */
class Result implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly OrderResolver $orderResolver,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderStatusService $statusService,
        private readonly TransactionRepository $transactions,
        private readonly ErrorMessages $errorMessages,
        private readonly UrlProvider $urlProvider,
        private readonly PageState $pageState,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly Log $log
    ) {
    }

    public function execute(): ResultInterface
    {
        $order = $this->orderResolver->resolve($this->request, 'r', OrderToken::PURPOSE_RESULT);
        if (!$order) {
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        if ($order->getState() === Order::STATE_PENDING_PAYMENT && $this->canCheckNow($order)) {
            try {
                $this->statusService->check((int)$order->getId(), StatusChecker::SOURCE_CUSTOMER, false, 10);
            } catch (\Throwable $e) {
                $this->log->error('Result page check failed', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            }
            $order = $this->orderResolver->resolve($this->request, 'r', OrderToken::PURPOSE_RESULT) ?? $order;
        }
        $isOwnSession = $this->orderResolver->isSessionOrder($order);

        switch ($order->getState()) {
            case Order::STATE_PROCESSING:
            case Order::STATE_COMPLETE:
            case Order::STATE_PAYMENT_REVIEW:
                if ($isOwnSession) {
                    $this->checkoutSession->setLastQuoteId($order->getQuoteId())
                        ->setLastSuccessQuoteId($order->getQuoteId())
                        ->setLastOrderId($order->getId())
                        ->setLastRealOrderId($order->getIncrementId())
                        ->setLastOrderStatus($order->getStatus());
                    return $this->redirectFactory->create()->setPath('checkout/onepage/success');
                }
                return $this->page($order, $order->getState() === Order::STATE_PAYMENT_REVIEW ? 'review' : 'paid', '');

            case Order::STATE_CANCELED:
                $message = (string)$this->failureMessage($order, $isOwnSession);
                if ($isOwnSession) {
                    $this->checkoutSession->restoreQuote();
                    $this->messageManager->addErrorMessage($message);
                    return $this->redirectFactory->create()->setPath('checkout/cart');
                }
                return $this->page($order, 'failed', $message);

            default:
                // Waiting for payment, on hold, or anything an admin set meanwhile: no conclusions, no actions.
                return $this->page($order, 'pending', '');
        }
    }

    private function failureMessage(Order $order, bool $basketRestored): \Magento\Framework\Phrase
    {
        foreach ($this->transactions->getForOrder((int)$order->getId(), 3) as $transaction) {
            if ($transaction->getStatus() === Transaction::STATUS_REVERSED) {
                return __('The order had been cancelled before your payment arrived, so the payment has been given back to your card.');
            }
            if ($transaction->getStatus() === Transaction::STATUS_FAILED && $transaction->getData('error_code')) {
                $reason = $this->errorMessages->forCode((string)$transaction->getData('error_code'));
                return $basketRestored
                    ? __('Payment failed: %1 Your order was cancelled and your basket has been restored.', $reason)
                    : __('Payment failed: %1 The order was cancelled.', $reason);
            }
        }
        return __('The payment was not completed, so the order was cancelled. You can try again or choose another payment method.');
    }

    /**
     * At most one live check every 10 seconds per order (refreshing the page does not flood iyzico).
     */
    private function canCheckNow(Order $order): bool
    {
        return !$this->transactions->wasCheckedRecently((int)$order->getId(), 10);
    }

    private function page(Order $order, string $state, string $message): ResultInterface
    {
        $this->pageState->setData([
            'state' => $state,
            'increment_id' => (string)$order->getIncrementId(),
            'message' => $message,
            'check_url' => $this->urlProvider->getResultUrl((int)$order->getStoreId(), (int)$order->getId()),
        ]);
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Payment'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }
}
