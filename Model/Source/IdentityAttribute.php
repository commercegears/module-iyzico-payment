<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Source;

use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Customer and customer address text attributes that can hold the T.C. Kimlik No.
 * Values are "customer:<code>" or "address:<code>"; empty means not mapped.
 */
class IdentityAttribute implements OptionSourceInterface
{
    public const CUSTOMER = 'customer';
    public const ADDRESS = 'address';

    /** Text attributes that can never hold an identity number. */
    private const SKIP = ['email', 'firstname', 'lastname', 'middlename', 'prefix', 'suffix', 'password_hash',
        'rp_token', 'confirmation', 'city', 'street', 'region', 'postcode', 'company', 'country_id',
        'default_billing', 'default_shipping', 'created_in', 'fax', 'telephone', 'vat_is_valid', 'vat_request_date',
        'vat_request_id', 'vat_request_success'];

    /** Attributes added by other SoftAware modules for their own purposes. */
    private const SKIP_PREFIX = 'softaware_';

    public function __construct(private readonly EavConfig $eavConfig)
    {
    }

    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => __('Not mapped: send the default number below')]];
        foreach ([self::CUSTOMER => __('Customer'), self::ADDRESS => __('Address')] as $type => $group) {
            $entity = $type === self::CUSTOMER ? 'customer' : 'customer_address';
            foreach ($this->eavConfig->getEntityType($entity)->getAttributeCollection() as $attribute) {
                $code = (string)$attribute->getAttributeCode();
                if ($attribute->getFrontendInput() !== 'text' || in_array($code, self::SKIP, true)
                    || str_starts_with($code, self::SKIP_PREFIX)) {
                    continue;
                }
                $label = (string)($attribute->getFrontendLabel() ?: $code);
                $options[] = ['value' => $type . ':' . $code, 'label' => sprintf('%s: %s (%s)', $group, $label, $code)];
            }
        }
        return $options;
    }
}
