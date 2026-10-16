<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Sitemap;

use Iranimij\OpenFilter\Api\Data\FilterConfigInterface as Filter;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Config\CategoryRule;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Layer\CategoryTree;
use Iranimij\OpenFilter\Model\Url\SlugProvider;
use Iranimij\OpenFilter\Model\Url\PathUrl;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\ItemProvider\ItemProviderInterface;
use Magento\Sitemap\Model\ResourceModel\Catalog\CategoryFactory as SitemapCategoryFactory;
use Magento\Sitemap\Model\SitemapItemInterfaceFactory;

/**
 * Filter pages search engines may index (1.1) in Magento's XML sitemap: on each category page of the store view, one
 * value of a filter marked "Let Google index pages filtered by this" (the pages OpenFilter's robots rule marks
 * INDEX,FOLLOW), only where the filter shows ("Where it shows") and with at least the configured number of products,
 * most products first, up to the configured number of URLs. Priority and change frequency follow the categories'.
 */
class FilterPages implements ItemProviderInterface
{
    /**
     * @param Config $config
     * @param FilterConfigProvider $filters
     * @param SlugProvider $slugs
     * @param SitemapCategoryFactory $categories
     * @param CategoryTree $tree
     * @param OptionCounts $counts
     * @param SitemapItemInterfaceFactory $itemFactory
     * @param ConfigReaderInterface $categoryConfig the sitemap settings of categories
     * @param CategoryRule $categoryRule "Where it shows"
     * @param PathUrl $pathUrl page URLs in the store's filter URL format (1.2)
     */
    public function __construct(
        private readonly Config $config,
        private readonly FilterConfigProvider $filters,
        private readonly SlugProvider $slugs,
        private readonly SitemapCategoryFactory $categories,
        private readonly CategoryTree $tree,
        private readonly OptionCounts $counts,
        private readonly SitemapItemInterfaceFactory $itemFactory,
        private readonly ConfigReaderInterface $categoryConfig,
        private readonly CategoryRule $categoryRule,
        private readonly PathUrl $pathUrl
    ) {
    }

    /**
     * @param int|string $storeId
     * @return array<int, \Magento\Sitemap\Model\SitemapItemInterface>
     */
    public function getItems($storeId)
    {
        $storeId = (int) $storeId;
        if (!$this->config->isSitemapEnabled($storeId) || !$this->config->isEnabled($storeId)) {
            return [];
        }
        $indexable = array_filter(
            $this->filters->getActive($storeId),
            // A filter that waits for another one (1.2) has no page of its own: its single value redirects away.
            static fn (Filter $filter): bool => !$filter->isBuiltin() && $filter->isSeoIndexable()
                && $filter->getDependsOn() === null
                && in_array($filter->getInputType(), ['select', 'multiselect'], true)
        );
        if ($indexable === []) {
            return [];
        }
        $pages = $this->pages($storeId, $indexable);
        $min = $this->config->getSitemapMinProducts($storeId);
        $pages = array_values(array_filter($pages, static fn (array $page): bool => $page['count'] >= $min));
        usort($pages, static fn (array $a, array $b): int => [$b['count'], $a['url']] <=> [$a['count'], $b['url']]);
        $max = $this->config->getSitemapMaxUrls($storeId);
        if ($max > 0) {
            $pages = array_slice($pages, 0, $max);
        }

        return array_map(fn (array $page) => $this->itemFactory->create([
            'url' => $page['url'],
            'updatedAt' => $page['updatedAt'],
            'priority' => $this->categoryConfig->getPriority($storeId),
            'changeFrequency' => $this->categoryConfig->getChangeFrequency($storeId),
        ]), $pages);
    }

    /**
     * @param int $storeId
     * @param Filter[] $indexable
     * @return array<int, array{url: string, count: int, updatedAt: ?string}>
     */
    private function pages(int $storeId, array $indexable): array
    {
        $slugs = $this->slugs->forStore($storeId);
        $pages = [];
        // Magento's sitemap generation (cron and admin) already runs in the store's frontend environment.
        foreach ((array) $this->categories->create()->getCollection($storeId) as $category) {
            $categoryId = (int) $category->getId();
            $path = $this->tree->pathIds($categoryId, $storeId);
            $shown = array_filter($indexable, fn (Filter $filter): bool => $this->categoryRule->showsIn($filter, $path));
            if ($shown === []) {
                continue;
            }
            $counts = $this->counts->forCategory($categoryId, array_map(
                static fn (Filter $filter): string => $filter->getCode(),
                array_values($shown)
            ));
            foreach ($shown as $filter) {
                $attributeId = (int) $filter->getAttributeId();
                $param = $slugs['attributes'][$attributeId] ?? $filter->getCode();
                foreach ($counts[$filter->getCode()] ?? [] as $optionId => $count) {
                    $slug = $slugs['options'][$attributeId][$optionId] ?? null;
                    if ($slug === null) {
                        continue;
                    }
                    $pages[] = [
                        'url' => $this->pathUrl->oneValue((string) $category->getUrl(), (string) $param, $slug, $storeId),
                        'count' => $count,
                        'updatedAt' => $category->getUpdatedAt(),
                    ];
                }
            }
        }

        return $pages;
    }
}
