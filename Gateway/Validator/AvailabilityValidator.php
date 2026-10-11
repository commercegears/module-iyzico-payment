<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Gateway\Validator;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Quote\Api\Data\CartInterface;
use CommerceGears\IyzicoPayment\Model\Config;

/**
 * Offers iyzico only when the keys are set, the basket's currency is one iyzico accepts and there is something to
 * pay (free orders never reach iyzico).
 */
class AvailabilityValidator extends AbstractValidator
{
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly Config $config
    ) {
        parent::__construct($resultFactory);
    }

    public function validate(array $validationSubject): ResultInterface
    {
        $payment = SubjectReader::readPayment($validationSubject)->getPayment();
        $quote = $payment->getQuote();
        if (!$quote instanceof CartInterface) {
            return $this->createResult(true);
        }
        $storeId = (int)$quote->getStoreId();
        $currency = (string)($quote->getQuoteCurrencyCode() ?: $quote->getStore()?->getCurrentCurrencyCode());
        $valid = $this->config->hasCredentials($storeId)
            && $this->config->isCurrencySupported($currency)
            && (float)$quote->getGrandTotal() > 0;
        return $this->createResult($valid);
    }
}
