<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Model\Url;

use Iranimij\OpenFilter\Api\FilterStateInterface;
use Iranimij\OpenFilter\Api\UrlCodecInterface;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Url\FilterState;
use Iranimij\OpenFilter\Model\Url\PathUrl;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class PathUrlTest extends TestCase
{
    private const NAMES = ['color' => 'color', 'size' => 'size', 'size-eu' => 'size_eu', 'price' => 'price'];

    public function testFiltersBecomePathSegmentsInCanonicalOrderAndTheRestStaysInTheQuery(): void
    {
        $url = $this->path()->build(
            new FilterState(['color' => ['black', 'blue'], 'size' => ['m']]),
            'https://shop/women/tops-women.html',
            ['p' => '2', 'product_list_order' => 'price', 'color' => 'ignored'],
            1
        );

        self::assertSame('https://shop/women/tops-women/color-black,blue/size-m.html?p=2&product_list_order=price', $url);
        self::assertSame(
            'https://shop/women/tops-women/price--50.html',
            $this->path()->build(new FilterState(['price' => ['-50']]), 'https://shop/women/tops-women.html', [], 1),
            'an open range'
        );
    }

    public function testQueryWhereAPathWouldMisleadOrCannotBeBuilt(): void
    {
        $path = $this->path();
        self::assertSame('QUERY', $path->build(new FilterState([]), 'https://shop/women.html', [], 1), 'no filters');
        self::assertSame('QUERY', $path->build(new FilterState(['color' => ['red']]), 'https://shop/catalog/category/view/id/12', [], 1), 'no rewrite');
        self::assertSame(
            'https://shop/women/size-m/size-eu-eu-38.html',
            $path->build(new FilterState(['size' => ['m'], 'size-eu' => ['eu-38']]), 'https://shop/women.html', [], 1),
            'the longest name reads size-eu-eu-38 back right'
        );
        self::assertSame(
            'https://shop/women.html?size=eu-38',
            $path->build(new FilterState(['size' => ['eu-38']]), 'https://shop/women.html', [], 1),
            '"size-eu-38" would read as size-eu = 38: query'
        );
        self::assertSame('https://shop/women/color-red', $this->path('')->build(new FilterState(['color' => ['red']]), 'https://shop/women', [], 1), 'no suffix');
    }

    public function testPathsSplitIntoTheCategoryAndItsFilters(): void
    {
        $path = $this->path();

        self::assertSame(
            ['women/tops-women.html', ['color' => 'black,blue', 'size' => 'm'], 'color-black,blue/size-m'],
            $path->parse('women/tops-women/color-black,blue/size-m.html', 1)
        );
        self::assertSame(['women.html', ['size-eu' => '38'], 'size-eu-38'], $path->parse('women/size-eu-38.html', 1), 'the longest name');
        self::assertSame(['women.html', ['color' => 'red'], 'color-red'], $path->parse('women/color-red.html', 1));
        self::assertNull($path->parse('women/tops-women.html', 1), 'a plain category');
        self::assertNull($path->parse('color-red.html', 1), 'a filter needs a category before it');
        self::assertNull($path->parse('women/color-red', 1), 'without the suffix');
        self::assertSame(['women/color-red.html', ['color' => 'blue'], 'color-blue'], $path->parse('women/color-red/color-blue.html', 1), 'a repeated filter ends the filters');
        self::assertSame(['women', ['color' => 'red'], 'color-red'], $this->path('')->parse('women/color-red', 1));
    }

    public function testCanonicalWhenTheSegmentsAndTheQueryAreWhereBuildPutsThem(): void
    {
        $path = $this->path();
        $state = new FilterState(['color' => ['black'], 'size' => ['eu-38']]);

        self::assertTrue($path->isCanonical($state, 'color-black', ['size' => 'eu-38', 'p' => '2'], 1));
        self::assertFalse($path->isCanonical($state, 'color-black', [], 1), 'size is missing from the query');
        self::assertFalse($path->isCanonical(new FilterState(['color' => ['black']]), '', ['color' => 'black'], 1), 'a query link');
        self::assertFalse($path->isCanonical(new FilterState(['color' => ['black'], 'size' => ['m']]), 'size-m/color-black', [], 1), 'order');
        self::assertTrue($path->isCanonical(new FilterState([]), '', ['p' => '3'], 1));
        self::assertSame('https://shop/women/color-red.html', $path->oneValue('https://shop/women.html', 'color', 'red', 1));
        self::assertSame('women.html?size=eu-38', $path->oneValue('women.html', 'size', 'eu-38', 1));
    }

    private function path(string $suffix = '.html'): PathUrl
    {
        $config = $this->createStub(Config::class);
        $config->method('getUrlFormat')->willReturn(Config::URL_FORMAT_PATH);
        $codec = $this->createStub(UrlCodecInterface::class);
        $codec->method('getParameterNames')->willReturn(self::NAMES);
        $codec->method('encode')->willReturnCallback(static function (FilterStateInterface $state): array {
            $params = [];
            foreach (array_keys(self::NAMES) as $name) {
                if ($state->get($name) !== []) {
                    $params[$name] = implode(',', $state->get($name));
                }
            }

            return $params;
        });
        $codec->method('urlFor')->willReturnCallback(static fn (FilterStateInterface $state, string $base, array $keep): string
            => $state->getValues() === [] ? 'QUERY' : (str_contains($base, '/catalog/') ? 'QUERY' : $base . '?' . http_build_query(array_map(
                static fn (array $v): string => implode(',', $v),
                $state->getValues()
            ))));
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturn($suffix);

        return new PathUrl($config, $codec, $scope);
    }
}
