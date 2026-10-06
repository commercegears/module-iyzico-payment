<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\DataObject;

/**
 * What the payment and result pages show; filled by the controller for the templates (one per request).
 *
 * Keys: state (frame|error|paid|review|pending|failed), increment_id, amount, frame_url, cancel_token, message,
 * retry_url, check_url.
 */
class PageState extends DataObject
{
}
