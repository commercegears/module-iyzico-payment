<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model\Request;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\App\ResourceConnection;

/**
 * One category name per product for iyzico's basket items ("category1"), read in two queries.
 */
class CategoryNames
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * @param int[] $productIds
     * @return array<int, string> product id => category name (deepest category the product is in)
     */
    public function get(array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (!$productIds) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(['ccp' => $this->resource->getTableName('catalog_category_product')], ['product_id', 'category_id'])
            ->join(['cce' => $this->resource->getTableName('catalog_category_entity')], 'cce.entity_id = ccp.category_id', [])
            ->where('ccp.product_id IN (?)', $productIds)
            ->where('cce.level >= ?', 2)
            ->order(['cce.level DESC', 'ccp.category_id ASC']);
        $productCategory = [];
        foreach ($connection->fetchAll($select) as $row) {
            $productCategory[(int)$row['product_id']] ??= (int)$row['category_id'];
        }
        if (!$productCategory) {
            return [];
        }
        $collection = $this->categoryCollectionFactory->create()
            ->setStoreId($storeId)
            ->addAttributeToSelect('name')
            ->addIdFilter(array_values(array_unique($productCategory)));
        $names = [];
        foreach ($collection as $category) {
            $names[(int)$category->getId()] = (string)$category->getName();
        }
        $result = [];
        foreach ($productCategory as $productId => $categoryId) {
            if (($names[$categoryId] ?? '') !== '') {
                $result[$productId] = $names[$categoryId];
            }
        }
        return $result;
    }
}
