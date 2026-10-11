<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model;

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
