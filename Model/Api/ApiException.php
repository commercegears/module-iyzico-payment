<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Api;

/**
 * No usable answer from iyzico (not configured, network error, timeout, HTTP 5xx, not JSON).
 * The outcome of the call is unknown: never treat it as a payment failure.
 */
class ApiException extends \RuntimeException
{
}
