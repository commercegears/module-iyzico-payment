<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * iyzico refused to open the Checkout Form for the order. The phrase is safe to show to the customer.
 */
class PaymentStartException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        private readonly string $errorCode = '',
        private readonly string $iyzicoMessage = ''
    ) {
        parent::__construct($phrase);
    }

    public function getIyzicoCode(): string
    {
        return $this->errorCode;
    }

    public function getIyzicoMessage(): string
    {
        return $this->iyzicoMessage;
    }
}
