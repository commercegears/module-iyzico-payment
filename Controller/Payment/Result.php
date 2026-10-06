<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Model\ErrorMessages;
use Softaware\IyzicoPayment\Model\Log;
use Softaware\IyzicoPayment\Model\OrderStatusService;
use Softaware\IyzicoPayment\Model\OrderToken;
use Softaware\IyzicoPayment\Model\PageState;
use Softaware\IyzicoPayment\Model\StatusChecker;
use Softaware\IyzicoPayment\Model\Transaction;
use Softaware\IyzicoPayment\Model\TransactionRepository;
use Softaware\IyzicoPayment\Model\UrlProvider;

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

            case Order::STATE_PENDING_PAYMENT:
            case Order::STATE_NEW:
                return $this->page($order, 'pending', '');

            default:
                $message = (string)$this->failureMessage($order);
                if ($isOwnSession) {
                    $this->checkoutSession->restoreQuote();
                    $this->messageManager->addErrorMessage($message);
                    return $this->redirectFactory->create()->setPath('checkout/cart');
                }
                return $this->page($order, 'failed', $message);
        }
    }

    private function failureMessage(Order $order): \Magento\Framework\Phrase
    {
        foreach ($this->transactions->getForOrder((int)$order->getId(), 3) as $transaction) {
            if ($transaction->getStatus() === Transaction::STATUS_REVERSED) {
                return __('The order had been cancelled before your payment arrived, so the payment has been given back to your card.');
            }
            if ($transaction->getStatus() === Transaction::STATUS_FAILED && $transaction->getData('error_code')) {
                return __('Payment failed: %1 Your order was cancelled and your basket has been restored.', $this->errorMessages->forCode((string)$transaction->getData('error_code')));
            }
        }
        return __('The payment was not completed, so the order was cancelled. You can try again or choose another payment method.');
    }

    /**
     * At most one live check every 10 seconds per order (refreshing the page does not flood iyzico).
     */
    private function canCheckNow(Order $order): bool
    {
        foreach ($this->transactions->getForOrder((int)$order->getId(), 1) as $transaction) {
            $checked = (string)$transaction->getData('checked_at');
            return $checked === '' || strtotime($checked . ' UTC') < time() - 10;
        }
        return true;
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
