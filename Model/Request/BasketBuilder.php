<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Model\Request;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Item;
use CommerceGears\IyzicoPayment\Model\Amount;

/**
 * Builds iyzico basket items from an order, in the order currency, summing exactly to the order's grand total.
 *
 * Products (configurable/bundle parents once; dynamic bundles from their children) with their discounts, shipping
 * as its own item. Anything else that changes the total (gift wrapping, fees, store credit, gift cards, reward
 * points, rounding) is reconciled against the grand total: extra charges become an "Other charges" item, reductions
 * are spread over the items in proportion. Items are never zero or negative (iyzico rejects them).
 */
class BasketBuilder
{
    public const SHIPPING_ID = 'shipping';
    public const ADJUSTMENT_ID = 'adjustment';

    public function __construct(private readonly CategoryNames $categoryNames)
    {
    }

    /**
     * @return array{price: string, items: array<int, array{id: string, name: string, category1: string, itemType: string, price: string}>}
     */
    public function build(OrderInterface $order): array
    {
        $target = Amount::toMinor($order->getGrandTotal());
        $lines = [];
        $productIds = [];
        /** @var Item $item */
        foreach ($order->getAllVisibleItems() as $item) {
            $minor = Amount::toMinor($this->itemAmount($item));
            if ($minor <= 0) {
                continue;
            }
            $productIds[] = (int)$item->getProductId();
            $lines[] = [
                'id' => (string)$item->getItemId(),
                'name' => $this->clean((string)$item->getName(), 'Product'),
                'product_id' => (int)$item->getProductId(),
                'itemType' => $item->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'minor' => $minor,
            ];
        }
        $shipping = Amount::toMinor(
            (float)$order->getShippingInclTax() - (float)$order->getShippingDiscountAmount()
        );
        if ($shipping > 0) {
            $lines[] = [
                'id' => self::SHIPPING_ID,
                'name' => $this->clean((string)$order->getShippingDescription(), 'Shipping'),
                'product_id' => 0,
                'category1' => 'Shipping',
                'itemType' => 'VIRTUAL',
                'minor' => $shipping,
            ];
        }

        $lines = $this->reconcile($lines, $target);

        $categories = $this->categoryNames->get($productIds, (int)$order->getStoreId());
        $items = [];
        foreach ($lines as $line) {
            $items[] = [
                'id' => $line['id'],
                'name' => $line['name'],
                'category1' => $line['category1'] ?? $this->clean($categories[$line['product_id']] ?? '', 'Product'),
                'itemType' => $line['itemType'],
                'price' => Amount::format(Amount::fromMinor($line['minor'])),
            ];
        }
        return ['price' => Amount::format(Amount::fromMinor($target)), 'items' => $items];
    }

    /**
     * Makes the lines sum exactly to $target (minor units); every line stays above zero.
     */
    public function reconcile(array $lines, int $target): array
    {
        if ($target <= 0) {
            return [];
        }
        $sum = array_sum(array_column($lines, 'minor'));
        if ($sum < $target) {
            $lines[] = [
                'id' => self::ADJUSTMENT_ID,
                'name' => 'Other charges',
                'product_id' => 0,
                'category1' => 'Other',
                'itemType' => 'VIRTUAL',
                'minor' => $target - $sum,
            ];
            return $lines;
        }
        if ($sum > $target) {
            foreach ($lines as $i => $line) {
                $lines[$i]['minor'] = intdiv($line['minor'] * $target, $sum);
            }
            $lines = array_values(array_filter($lines, static fn(array $line): bool => $line['minor'] > 0));
            if (!$lines) {
                return [[
                    'id' => self::ADJUSTMENT_ID, 'name' => 'Order', 'product_id' => 0,
                    'category1' => 'Other', 'itemType' => 'PHYSICAL', 'minor' => $target,
                ]];
            }
            $residue = $target - array_sum(array_column($lines, 'minor'));
            $largest = array_keys(array_column($lines, 'minor'), max(array_column($lines, 'minor')))[0];
            $lines[$largest]['minor'] += $residue;
        }
        return $lines;
    }

    private function itemAmount(Item $item): float
    {
        $children = $item->getChildrenItems();
        if ($children && $item->isChildrenCalculated()) {
            $amount = 0.0;
            foreach ($children as $child) {
                $amount += (float)$child->getRowTotalInclTax() - (float)$child->getDiscountAmount();
            }
            return $amount;
        }
        return (float)$item->getRowTotalInclTax() - (float)$item->getDiscountAmount();
    }

    private function clean(string $value, string $fallback): string
    {
        $value = trim((string)preg_replace('/\s+/u', ' ', strip_tags($value)));
        return $value === '' ? $fallback : mb_substr($value, 0, 100);
    }
}
