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
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Model\Order;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Config;
use Softaware\IyzicoPayment\Model\LockBusyException;
use Softaware\IyzicoPayment\Model\Log;
use Softaware\IyzicoPayment\Model\OrderCanceller;
use Softaware\IyzicoPayment\Model\OrderLock;
use Softaware\IyzicoPayment\Model\OrderToken;
use Softaware\IyzicoPayment\Model\PageState;
use Softaware\IyzicoPayment\Model\PaymentStarter;
use Softaware\IyzicoPayment\Model\PaymentStartException;
use Softaware\IyzicoPayment\Model\ResultProcessor;
use Softaware\IyzicoPayment\Model\StatusChecker;
use Softaware\IyzicoPayment\Model\Transaction;
use Softaware\IyzicoPayment\Model\UrlProvider;

/**
 * Payment page: opens (or reuses) iyzico's Checkout Form for the order this visitor just placed, then redirects to
 * iyzico or shows the form in a frame on this page. An order that is already paid or cancelled goes to the result
 * page; a second payment form is never opened while a valid one exists.
 */
class Start implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderResolver $orderResolver,
        private readonly OrderLock $orderLock,
        private readonly PaymentStarter $starter,
        private readonly StatusChecker $checker,
        private readonly OrderCanceller $canceller,
        private readonly Config $config,
        private readonly UrlProvider $urlProvider,
        private readonly PageState $pageState,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Log $log
    ) {
    }

    public function execute(): ResultInterface
    {
        $order = $this->orderResolver->resolve($this->request, 't', OrderToken::PURPOSE_PAY);
        if (!$order) {
            $this->messageManager->addNoticeMessage(__('There is no order waiting for payment.'));
            return $this->redirectFactory->create()->setPath('checkout/cart');
        }
        $orderId = (int)$order->getId();
        $storeId = (int)$order->getStoreId();
        if (!in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true)) {
            return $this->redirectFactory->create()->setUrl($this->urlProvider->getResultUrl($storeId, $orderId));
        }

        $inFrame = $this->config->getDisplayMode($storeId) === Config::MODE_IFRAME;
        try {
            $transaction = $this->orderLock->run($orderId, function (Order $order) use ($inFrame): ?Transaction {
                if (!in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true)) {
                    return null;
                }
                $reusable = $this->starter->findReusable($order, $inFrame);
                if ($reusable) {
                    return $reusable;
                }
                if ($this->starter->hasOpenForms($order)) {
                    // An earlier payment form of this order may have been paid meanwhile (other tab, lost callback).
                    $outcome = $this->checker->check($order, StatusChecker::SOURCE_CUSTOMER, false, 10);
                    if (!in_array($outcome, [ResultProcessor::OUTCOME_FAILED, ResultProcessor::OUTCOME_UNKNOWN], true)
                        || ($order->getState() !== Order::STATE_PENDING_PAYMENT && $order->getState() !== Order::STATE_NEW)
                    ) {
                        return null;
                    }
                }
                try {
                    return $this->starter->start($order, $inFrame);
                } catch (PaymentStartException $e) {
                    $this->canceller->cancel($order, __(
                        'iyzico did not open the payment form (%1 %2). The order was cancelled.',
                        $e->getIyzicoCode(),
                        $e->getIyzicoMessage()
                    ));
                    throw $e;
                }
            });
        } catch (PaymentStartException $e) {
            if ($this->orderResolver->isSessionOrder($order)) {
                $this->checkoutSession->restoreQuote();
                $this->messageManager->addErrorMessage($e->getMessage());
                return $this->redirectFactory->create()->setPath('checkout/cart');
            }
            return $this->redirectFactory->create()->setUrl($this->urlProvider->getResultUrl($storeId, $orderId));
        } catch (ApiException | LockBusyException $e) {
            $this->log->error('Payment page could not be prepared', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            return $this->page($order, 'error', null);
        }

        if (!$transaction) {
            return $this->redirectFactory->create()->setUrl($this->urlProvider->getResultUrl($storeId, $orderId));
        }
        $pageUrl = (string)$transaction->getData('payment_page_url');
        if (!$inFrame) {
            return $this->redirectFactory->create()->setUrl($pageUrl);
        }
        return $this->page($order, 'frame', $pageUrl . (str_contains($pageUrl, '?') ? '&' : '?') . 'iframe=true');
    }

    private function page(Order $order, string $state, ?string $frameUrl): ResultInterface
    {
        $token = (string)$this->request->getParam('t', '');
        $this->pageState->setData([
            'state' => $state,
            'increment_id' => (string)$order->getIncrementId(),
            'amount' => $this->priceCurrency->format(
                (float)$order->getGrandTotal(),
                false,
                PriceCurrencyInterface::DEFAULT_PRECISION,
                $order->getStoreId(),
                $order->getOrderCurrencyCode()
            ),
            'frame_url' => $frameUrl,
            'order_token' => $token,
            'retry_url' => $this->urlProvider->url((int)$order->getStoreId(), 'softaware_iyzico/payment/start', $token !== '' ? ['_query' => ['t' => $token]] : []),
        ]);
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Secure Payment'));
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        return $page;
    }
}
