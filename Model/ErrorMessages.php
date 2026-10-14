<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

use Magento\Framework\Phrase;

/**
 * Customer-facing texts for iyzico error codes (docs: Error Codes). The raw code and iyzico's message are logged
 * and kept on the payment row for the merchant; customers only see these short, translatable texts.
 */
class ErrorMessages
{
    private const GROUPS = [
        'funds' => ['10051', '10224', '10234', '10248'],
        'expiry' => ['10054', '10229'],
        'cvc' => ['10084', '10210', '10212'],
        'card' => ['10041', '10043', '10057', '10093', '10201', '10209', '10213', '10215', '10225', '10226', '10227', '10233', '10235', '12'],
        'installment' => ['10218', '10222', '10250'],
        'foreign' => ['10221'],
        'threeds' => ['10217', '10230', 'CALLBACK_3DS', 'INIT_3DS', '5014', 'NOT_PAID_3DS'],
        'aborted' => ['10239', 'NOT_PAID'],
        'timeout' => ['10214', '10216', '10219', '10228', '10240', '10241', '10242'],
        'fraud' => ['6000', '6001'],
    ];

    /** Prefix of the code stored for a failed 3-D Secure verification (iyzico sends only mdStatus then). */
    public const THREEDS_PREFIX = 'MDSTATUS_';

    public function forCode(?string $code): Phrase
    {
        $code = trim((string)$code);
        if (str_starts_with($code, self::THREEDS_PREFIX)) {
            return $this->phrase('threeds');
        }
        foreach (self::GROUPS as $group => $codes) {
            if (in_array($code, $codes, true)) {
                return $this->phrase($group);
            }
        }
        return $this->phrase('');
    }

    private function phrase(string $group): Phrase
    {
        return match ($group) {
            'funds' => __('Your card does not have enough limit or balance for this payment. Please use another card.'),
            'expiry' => __('The card has expired or the expiry date was entered incorrectly.'),
            'cvc' => __('The security code (CVC) was not accepted. Please check it and try again.'),
            'card' => __('This card cannot be used for this payment. Please contact your bank or use another card.'),
            'installment' => __('Installments are not available for this card. Please pay in a single payment or use another card.'),
            'foreign' => __('Cards issued outside Türkiye cannot be used for this payment.'),
            'threeds' => __('The 3-D Secure verification was not completed. Please try again.'),
            'aborted' => __('The payment was cancelled.'),
            'timeout' => __('Your bank did not respond in time. Please try again in a few minutes.'),
            'fraud' => __('The payment could not be completed. Please contact us or choose another payment method.'),
            default => __('Your bank did not approve the payment. Please try again or choose another payment method.'),
        };
    }
}
