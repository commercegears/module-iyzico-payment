<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

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
