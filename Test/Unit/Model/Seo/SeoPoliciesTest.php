<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Model\Seo;

use Iranimij\OpenFilter\Model\Url\PathUrl;
use Iranimij\OpenFilter\Model\Config\DependencyRule;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Config\Defaults;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Layer\CategoryTree;
use Iranimij\OpenFilter\Model\Seo\CanonicalResolver;
use Iranimij\OpenFilter\Model\Seo\MaxFiltersGuard;
use Iranimij\OpenFilter\Model\Seo\NofollowPolicy;
use Iranimij\OpenFilter\Model\Seo\RobotsResolver;
use Iranimij\OpenFilter\Model\Url\FilterState;
use Iranimij\OpenFilter\Model\Url\Range;
use Iranimij\OpenFilter\Model\Url\SlugProvider;
use Iranimij\OpenFilter\Model\Url\UrlCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeoPoliciesTest extends TestCase
{
    private const BASE = 'https://shop.test/women/tops.html';

    /**
     * @return array<string, array{array<string, string[]>, int, bool, bool}>
     */
    public static function robotsCases(): array
    {
        return [
            'no filter' => [[], 0, false, true],
            'no filter on search' => [[], 0, true, true],
            'one value of an indexable filter' => [['color' => ['50']], 0, false, true],
            'one value of a filter not marked indexable' => [['size' => ['168']], 0, false, false],
            'two values of an indexable filter' => [['color' => ['50', '49']], 0, false, false],
            'two filters' => [['color' => ['50'], 'size' => ['168']], 0, false, false],
            'indexable value plus core category filter' => [['color' => ['50']], 1, false, false],
            'only core category filter' => [[], 1, false, false],
            'search page, indexable value' => [['color' => ['50']], 0, true, false],
            'hidden or unknown filter' => [['ghost' => ['1']], 0, false, false],
        ];
    }

    /**
     * @dataProvider robotsCases
     * @param array<string, string[]> $values
     */
    #[DataProvider('robotsCases')]
    public function testRobots(array $values, int $other, bool $search, bool $indexable): void
    {
        $robots = new RobotsResolver($this->configProvider());
        $state = new FilterState($values);

        self::assertSame($indexable, $robots->isIndexable($state, $other, $search, 1));
        self::assertSame($indexable ? null : 'NOINDEX,FOLLOW', $robots->robots($state, $other, $search, 1));
    }

    public function testCanonicalOfAnIndexablePageIsItselfWithThePageNumberOnly(): void
    {
        $canonical = new CanonicalResolver($this->codec(), $this->createStub(PathUrl::class));

        self::assertSame(
            self::BASE . '?color=red',
            $canonical->resolve(new FilterState(['color' => ['50']]), true, self::BASE, 1, 1)
        );
        self::assertSame(
            self::BASE . '?color=red&p=3',
            $canonical->resolve(new FilterState(['color' => ['50']]), true, self::BASE, 3, 1)
        );
    }

    public function testCanonicalOfAnyOtherFilteredPageIsTheCategory(): void
    {
        $canonical = new CanonicalResolver($this->codec(), $this->createStub(PathUrl::class));

        self::assertSame(
            self::BASE,
            $canonical->resolve(new FilterState(['color' => ['50', '49']]), false, self::BASE, 2, 1)
        );
    }

    /**
     * @return array<string, array{int, int, bool, string}>
     */
    public static function nofollowCases(): array
    {
        return [
            'below the threshold' => [2, 1, false, ''],
            'at the threshold' => [2, 2, false, 'nofollow'],
            'above the threshold' => [2, 4, false, 'nofollow'],
            'a link that removes a value' => [2, 4, true, ''],
            'rule off' => [0, 9, false, ''],
            'threshold 1, first value' => [1, 1, false, 'nofollow'],
            'threshold 1, nothing active' => [1, 0, false, ''],
        ];
    }

    /**
     * @dataProvider nofollowCases
     */
    #[DataProvider('nofollowCases')]
    public function testNofollow(int $threshold, int $active, bool $selected, string $rel): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getNofollowThreshold')->willReturn($threshold);

        self::assertSame($rel, (new NofollowPolicy($config))->rel($active, $selected, 1));
    }

    public function testGuardKeepsTheFirstValuesInCanonicalOrder(): void
    {
        $guard = $this->guard(3);
        // Request order differs from canonical order: color before size, red before blue (option position).
        $state = new FilterState(['size' => ['168', '167'], 'color' => ['49', '50'], 'price' => ['20-80']]);

        $truncated = $guard->truncate($state, 1);

        self::assertNotNull($truncated);
        self::assertSame(3, $truncated->count());
        self::assertSame(['50', '49'], $truncated->get('color'));
        self::assertSame(['167'], $truncated->get('size'));
        self::assertFalse($truncated->has('price'));
    }

    public function testGuardLeavesStatesWithinTheLimitAndCanBeSwitchedOff(): void
    {
        $state = new FilterState(['color' => ['49', '50'], 'size' => ['168']]);

        self::assertNull($this->guard(3)->truncate($state, 1));
        self::assertNull($this->guard(0)->truncate($state, 1));
        self::assertNotNull($this->guard(2)->truncate($state, 1));
    }

    private function guard(int $limit): MaxFiltersGuard
    {
        $config = $this->createStub(Config::class);
        $config->method('getMaxActiveValues')->willReturn($limit);

        return new MaxFiltersGuard($config, $this->codec());
    }

    private function configProvider(): FilterConfigProvider
    {
        $defaults = new Defaults();
        $color = $defaults->forAttribute('color', 93, 'select', 'visual', 10)->setSeoIndexable(true);
        $configs = [
            'color' => $color,
            'size' => $defaults->forAttribute('size', 144, 'select', 'text', 20),
            'price' => $defaults->forBuiltin('price'),
        ];
        $provider = $this->createStub(FilterConfigProvider::class);
        $provider->method('getActive')->willReturn($configs);

        return $provider;
    }

    private function codec(): UrlCodec
    {
        $slugProvider = $this->createStub(SlugProvider::class);
        $slugProvider->method('forStore')->willReturn([
            'attributes' => [93 => 'color', 144 => 'size'],
            'options' => [93 => [50 => 'red', 49 => 'blue'], 144 => [167 => 's', 168 => 'm']],
        ]);

        return new UrlCodec($this->configProvider(), $slugProvider, new Range(), $this->createStub(CategoryTree::class), new DependencyRule());
    }
}
