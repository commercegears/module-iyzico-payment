<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\ResourceModel\Transaction;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use CommerceGears\IyzicoPayment\Model\ResourceModel\Transaction as Resource;
use CommerceGears\IyzicoPayment\Model\Transaction;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    protected function _construct()
    {
        $this->_init(Transaction::class, Resource::class);
    }
}
