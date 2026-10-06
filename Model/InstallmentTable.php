<?php
/**
 * @category  Softaware
 * @package   Softaware_IyzicoPayment
 * @copyright Copyright © Softaware Commerce Ltd (https://softawarecommerce.com/)
 */
declare(strict_types=1);

namespace Softaware\IyzicoPayment\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Softaware\IyzicoPayment\Model\Api\ApiException;
use Softaware\IyzicoPayment\Model\Api\Client;

/**
 * Installment options for an amount (iyzico installment service without a card number: one row per card
 * programme). Cached for 6 hours per store and amount; a failure is cached for 10 minutes and gives an empty table.
 * Installments are offered in Turkish lira only.
 */
class InstallmentTable
{
    private const CACHE_TTL = 21600;
    private const FAILURE_TTL = 600;
    public const CACHE_TAG = 'SOFTAWARE_IYZICO_INSTALLMENTS';

    public function __construct(
        private readonly Client $client,
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * @return array{counts: int[], rows: array<int, array{name: string, cells: array<int, array{each: string, total: string}>}>}
     */
    public function get(float $amount, int $storeId): array
    {
        $empty = ['counts' => [], 'rows' => []];
        $minor = Amount::toMinor($amount);
        if ($minor <= 0 || !$this->config->isInstallmentTableEnabled($storeId) || !$this->config->hasCredentials($storeId)) {
            return $empty;
        }
        $key = 'softaware_iyzico_inst_' . $this->config->getEnvironment($storeId) . '_' . $storeId . '_' . $minor;
        $cached = $this->cache->load($key);
        if ($cached !== false && $cached !== null) {
            return (array)$this->json->unserialize($cached);
        }
        $table = $empty;
        $ttl = self::FAILURE_TTL;
        try {
            $response = $this->client->post(Client::PATH_INSTALLMENT, [
                'locale' => $this->config->getLocale($storeId),
                'conversationId' => 'installments',
                'price' => Amount::format($amount),
            ], $storeId, true, 5);
            if (($response['status'] ?? '') === 'success') {
                $table = $this->build((array)($response['installmentDetails'] ?? []), $this->config->getInstallments($storeId));
                $ttl = self::CACHE_TTL;
            }
        } catch (ApiException) {
            // Shown without the table; tried again after the short cache time.
        }
        $this->cache->save($this->json->serialize($table), $key, [self::CACHE_TAG], $ttl);
        return $table;
    }

    /**
     * @param int[] $allowed installment counts allowed in the configuration (empty = all)
     */
    public function build(array $details, array $allowed): array
    {
        $rows = [];
        $counts = [];
        foreach ($details as $detail) {
            $name = trim((string)($detail['cardFamilyName'] ?? ''));
            if ($name === '' || isset($rows[$name])) {
                continue;
            }
            $cells = [];
            foreach ((array)($detail['installmentPrices'] ?? []) as $price) {
                $count = (int)($price['installmentNumber'] ?? 0);
                if ($count < 2 || ($allowed && !in_array($count, $allowed, true))) {
                    continue;
                }
                $cells[$count] = [
                    'each' => Amount::format($price['installmentPrice'] ?? 0),
                    'total' => Amount::format($price['totalPrice'] ?? 0),
                ];
                $counts[$count] = true;
            }
            if ($cells) {
                $rows[$name] = ['name' => mb_substr($name, 0, 60), 'cells' => $cells];
            }
        }
        $counts = array_keys($counts);
        sort($counts);
        return ['counts' => $counts, 'rows' => array_values($rows)];
    }
}
