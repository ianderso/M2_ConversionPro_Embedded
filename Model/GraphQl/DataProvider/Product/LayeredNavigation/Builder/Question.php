<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Celebros\ConversionPro\Model\GraphQl\DataProvider\Product\LayeredNavigation\Builder;

use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\AttributeOptionProvider;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\LayerBuilderInterface;
use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\AggregationValueInterface;
use Magento\Framework\Api\Search\BucketInterface;
use Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Formatter\LayerFormatter;

/**
 * @inheritdoc
 */
class Question implements LayerBuilderInterface
{
    /**
     * @var string
     * @see \Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Category::CATEGORY_BUCKET
     */
    private const PRICE_BUCKET = 'price_bucket';

    /**
     * @var string
     * @see \Magento\CatalogGraphQl\DataProvider\Product\LayeredNavigation\Builder\Price::PRICE_BUCKET
     */
    private const CATEGORY_BUCKET = 'category_bucket';

    /**
     * @var AttributeOptionProvider
     */
    private $attributeOptionProvider;

    /**
     * @var LayerFormatter
     */
    private $layerFormatter;

    /**
     * @var \Celebros\ConversionPro\Helper\Graphql\Data
     */
    private $graphqlHelper;

    /**
     * @var array
     */
    private $bucketNameFilter = [
        self::PRICE_BUCKET,
        self::CATEGORY_BUCKET
    ];

    /**
     * @param AttributeOptionProvider $attributeOptionProvider
     * @param LayerFormatter $layerFormatter
     * @param array $bucketNameFilter
     */
    public function __construct(
        AttributeOptionProvider $attributeOptionProvider,
        LayerFormatter $layerFormatter,
        \Celebros\ConversionPro\Helper\Graphql\Data $graphqlHelper,
        $bucketNameFilter = []
    ) {
        $this->attributeOptionProvider = $attributeOptionProvider;
        $this->layerFormatter = $layerFormatter;
        $this->graphqlHelper = $graphqlHelper;
        $this->bucketNameFilter = \array_merge($this->bucketNameFilter, $bucketNameFilter);
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @throws \Zend_Db_Statement_Exception
     */
    public function build(AggregationInterface $aggregation, ?int $storeId): array
    {
        if (!$this->graphqlHelper->isFilters()) {
            return [];
        }

        $attributeOptions = $this->getAttributeOptions($aggregation);

        // build layer per attribute
        $result = [];
        foreach ($this->getAttributeBuckets($aggregation) as $bucket) {
            $bucketName = $bucket->getName();
            $attributeCode = \preg_replace('~_bucket$~', '', $bucketName);
            $attribute = $attributeOptions[$attributeCode] ?? [];

            $result[$bucketName] = $this->layerFormatter->buildLayer(
                $attribute['attribute_label'] ?? $bucketName,
                \count($bucket->getValues()),
                $attribute['attribute_code'] ?? str_replace(" ", "_", $bucketName)
            );

            foreach ($bucket->getValues() as $value) {
                $metrics = $value->getMetrics();
                $result[$bucketName]['options'][] = $this->layerFormatter->buildItem(
                    $metrics['label'] ?? $metrics['value'],
                    $metrics['value'],
                    $metrics['count']
                );
            }
        }

        return $result;
    }

    /**
     * Get attribute buckets excluding specified bucket names
     *
     * @param AggregationInterface $aggregation
     * @return \Generator|BucketInterface[]
     */
    private function getAttributeBuckets(AggregationInterface $aggregation)
    {
        foreach ($aggregation->getBuckets() as $bucket) {
            if (\in_array($bucket->getName(), $this->bucketNameFilter, true)) {
                continue;
            }
            if ($this->isBucketEmpty($bucket)) {
                continue;
            }
            yield $bucket;
        }
    }

    /**
     * Check that bucket contains data
     *
     * @param BucketInterface|null $bucket
     * @return bool
     */
    private function isBucketEmpty(?BucketInterface $bucket): bool
    {
        return null === $bucket || !$bucket->getValues();
    }

    /**
     * Get list of attributes with options
     *
     * @param AggregationInterface $aggregation
     * @return array
     * @throws \Zend_Db_Statement_Exception
     */
    private function getAttributeOptions(AggregationInterface $aggregation): array
    {
        $attributeOptionIds = [];
        $attributes = [];
        foreach ($this->getAttributeBuckets($aggregation) as $bucket) {
            $attributes[] = \preg_replace('~_bucket$~', '', $bucket->getName());
            $attributeOptionIds[] = \array_map(
                function (AggregationValueInterface $value) {
                    return $value->getValue();
                },
                $bucket->getValues()
            );
        }

        if (!$attributeOptionIds) {
            return [];
        }

        return $this->attributeOptionProvider->getOptions(\array_merge(...$attributeOptionIds), null, $attributes);
    }
}
