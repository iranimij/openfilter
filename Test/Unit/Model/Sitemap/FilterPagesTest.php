<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Model\Sitemap;

use Iranimij\OpenFilter\Model\Url\PathUrl;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Config\CategoryRule;
use Iranimij\OpenFilter\Model\Config\Defaults;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Layer\CategoryTree;
use Iranimij\OpenFilter\Model\Sitemap\FilterPages;
use Iranimij\OpenFilter\Model\Sitemap\OptionCounts;
use Iranimij\OpenFilter\Model\Url\SlugProvider;
use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ItemProvider\ConfigReaderInterface;
use Magento\Sitemap\Model\ResourceModel\Catalog\Category as SitemapCategory;
use Magento\Sitemap\Model\ResourceModel\Catalog\CategoryFactory as SitemapCategoryFactory;
use Magento\Sitemap\Model\SitemapItem;
use Magento\Sitemap\Model\SitemapItemInterfaceFactory;
use PHPUnit\Framework\TestCase;

class FilterPagesTest extends TestCase
{
    public function testIndexableValuesWithEnoughProductsMostProductsFirstUpToTheLimit(): void
    {
        $urls = array_map(static fn ($item): string => $item->getUrl(), $this->pages(min: 2, max: 3)->getItems(1));

        self::assertSame([
            'women/tops.html?color=black',
            'women/tops.html?color=blue',
            'women/bottoms.html?color=black',
        ], $urls);
    }

    public function testOffMeansNothing(): void
    {
        self::assertSame([], $this->pages(enabled: false)->getItems(1));
    }

    public function testWhereItShowsIsRespected(): void
    {
        $urls = array_map(static fn ($item): string => $item->getUrl(), $this->pages(only: [21])->getItems(1));

        self::assertSame(['women/tops.html?color=black', 'women/tops.html?color=blue', 'women/tops.html?color=red'], $urls);
    }

    public function testHiddenWhereTheFilterIsExcluded(): void
    {
        $urls = array_map(static fn ($item): string => $item->getUrl(), $this->pages(except: [20])->getItems(1));

        self::assertSame([], $urls, 'both categories are below the excluded one');
    }

    public function testValuesWithoutAUrlNameAreLeftOut(): void
    {
        $urls = array_map(static fn ($item): string => $item->getUrl(), $this->pages(optionSlugs: [1 => 'red', 2 => 'blue'])->getItems(1));

        self::assertNotContains('women/tops.html?color=black', $urls);
        self::assertContains('women/tops.html?color=blue', $urls);
    }

    public function testNoIndexableFilterMeansNothing(): void
    {
        self::assertSame([], $this->pages(indexable: false)->getItems(1));
    }

    public function testPriorityAndFrequencyAreTheCategories(): void
    {
        $item = $this->pages()->getItems(1)[0];

        self::assertSame('0.5', $item->getPriority());
        self::assertSame('daily', $item->getChangeFrequency());
        self::assertSame('2026-10-01', $item->getUpdatedAt());
    }

    /**
     * @param int[] $except categories of "Hide in these categories"
     * @param array<int, string>|null $optionSlugs URL names of the colour options
     * @param int[] $only categories of "Only in these categories"; [] = everywhere
     */
    private function pages(
        bool $enabled = true,
        int $min = 1,
        int $max = 0,
        array $only = [],
        array $except = [],
        ?array $optionSlugs = null,
        bool $indexable = true
    ): FilterPages
    {
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isSitemapEnabled')->willReturn($enabled);
        $config->method('getSitemapMinProducts')->willReturn($min);
        $config->method('getSitemapMaxUrls')->willReturn($max);
        $colour = (new Defaults())->forAttribute('color', 93, 'select', 'visual', 10)->setSeoIndexable($indexable);
        if ($only !== []) {
            $colour->setCategoryMode('only')->setCategoryIds($only);
        }
        if ($except !== []) {
            $colour->setCategoryMode('except')->setCategoryIds($except);
        }
        $size = (new Defaults())->forAttribute('size', 144, 'select', 'text', 20);
        $filters = $this->createStub(FilterConfigProvider::class);
        $filters->method('getActive')->willReturn(['color' => $colour, 'size' => $size, 'price' => (new Defaults())->forBuiltin('price')->setSeoIndexable(true)]);
        $slugs = $this->createStub(SlugProvider::class);
        $slugs->method('forStore')->willReturn(['attributes' => [93 => 'color'], 'options' => [93 => $optionSlugs ?? [1 => 'red', 2 => 'blue', 3 => 'black']]]);
        $resource = $this->createStub(SitemapCategory::class);
        $resource->method('getCollection')->willReturn([
            new DataObject(['id' => 21, 'url' => 'women/tops.html', 'updated_at' => '2026-10-01']),
            new DataObject(['id' => 22, 'url' => 'women/bottoms.html', 'updated_at' => '2026-10-02']),
        ]);
        $categories = $this->createStub(SitemapCategoryFactory::class);
        $categories->method('create')->willReturn($resource);
        $tree = $this->createStub(CategoryTree::class);
        $tree->method('pathIds')->willReturnCallback(static fn (int $id): array => [2, 20, $id]);
        $counts = $this->createStub(OptionCounts::class);
        $counts->method('forCategory')->willReturnCallback(static fn (int $id): array => $id === 21
            ? ['color' => [1 => 1, 2 => 4, 3 => 9]]
            : ['color' => [3 => 2, 2 => 1]]);
        $factory = $this->createStub(SitemapItemInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (array $data) => new SitemapItem(
            $data['url'],
            $data['priority'],
            $data['changeFrequency'],
            $data['updatedAt']
        ));
        $categoryConfig = $this->createStub(ConfigReaderInterface::class);
        $categoryConfig->method('getPriority')->willReturn('0.5');
        $categoryConfig->method('getChangeFrequency')->willReturn('daily');

        $pathUrl = $this->createStub(PathUrl::class);
        $pathUrl->method('oneValue')->willReturnCallback(
            static fn (string $url, string $param, string $value): string => $url . '?' . rawurlencode($param) . '=' . rawurlencode($value)
        );

        return new FilterPages($config, $filters, $slugs, $categories, $tree, $counts, $factory, $categoryConfig, new CategoryRule(), $pathUrl);
    }
}
