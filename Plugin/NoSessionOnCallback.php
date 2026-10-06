<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Plugin;

use Magento\Framework\App\Request\Http;
use Magento\Framework\Session\SessionStartChecker;

/**
 * iyzico returns the customer with a cross-site POST, which carries no SameSite=Lax session cookie. Starting a
 * session there would replace the customer's session cookie (logging them out and losing the checkout session),
 * so the callback and the webhook run without a session. The callback then redirects (GET) to the result page,
 * where the customer's own session is available again. Same approach as Magento_Paypal's TransparentSessionChecker.
 */
class NoSessionOnCallback
{
    private const PATHS = ['/softaware_iyzico/payment/callback', '/softaware_iyzico/webhook'];

    public function __construct(private readonly Http $request)
    {
    }

    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result) {
            return false;
        }
        $path = (string)$this->request->getPathInfo();
        foreach (self::PATHS as $prefix) {
            if (str_contains($path, $prefix)) {
                return false;
            }
        }
        return true;
    }
}
