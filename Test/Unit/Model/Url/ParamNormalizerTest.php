<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Model\Url;

use Iranimij\OpenFilter\Model\Config\Defaults;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Layer\CategoryTree;
use Iranimij\OpenFilter\Model\Url\ParamNormalizer;
use Iranimij\OpenFilter\Model\Url\Range;
use Iranimij\OpenFilter\Model\Url\SlugProvider;
use Iranimij\OpenFilter\Model\Url\UrlCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ParamNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function queries(): array
    {
        return [
            'canonical' => [['color' => 'red,blue', 'size' => 'm', 'p' => '2'], false, false],
            'canonical, other params anywhere' => [['utm_source' => 'x', 'color' => 'red', 'product_list_order' => 'name'], false, false],
            'no filters' => [['p' => '3', 'q' => 'tee'], false, false],
            'value order' => [['color' => 'blue,red'], true, false],
            // Magento's Varnish configuration sorts query parameters, so their order alone must never redirect (a loop).
            'parameter order' => [['size' => 'm', 'color' => 'red'], false, false],
            'parameter order with a value to normalise' => [['size' => 'M', 'color' => 'red'], true, false],
            'upper case' => [['color' => 'RED'], true, false],
            'option id' => [['color' => '50'], true, false],
            'array syntax' => [['color' => ['red', 'blue']], true, false],
            'spaces' => [['color' => 'red, blue'], true, false],
            'duplicate' => [['color' => 'red,red'], true, true],
            'junk value' => [['color' => 'red,nonsense'], true, true],
            'only junk' => [['color' => 'nonsense'], true, true],
            'range normalised' => [['price' => '80-20'], true, false],
            'range from a form (no JavaScript)' => [['price_from' => '20', 'price_to' => '50'], true, false],
            'open range from a form' => [['price_from' => '20', 'price_to' => ''], true, false],
            'empty range form' => [['price_from' => '', 'price_to' => ''], true, false],
        ];
    }

    public function testRangeFormFieldsFoldIntoTheFilterParameterAndAreDropped(): void
    {
        $result = $this->normalizer()->check(['color' => 'red', 'price_from' => '20', 'price_to' => '50', 'q' => 'x'], 1);

        self::assertSame(['20-50'], $result['state']->get('price'));
        self::assertSame(['price_from', 'price_to'], $result['drop']);
        self::assertSame([], $this->normalizer()->check(['color' => 'red'], 1)['drop']);
    }

    /**
     * @dataProvider queries
     * @param array<string, mixed> $query
     */
    #[DataProvider('queries')]
    public function testCheck(array $query, bool $redirect, bool $changed): void
    {
        $result = $this->normalizer()->check($query, 1);

        self::assertSame($redirect, $result['redirect'], 'redirect');
        self::assertSame($changed, $result['changed'], 'changed');
    }

    private function normalizer(): ParamNormalizer
    {
        $defaults = new Defaults();
        $configs = [
            'color' => $defaults->forAttribute('color', 93, 'select', 'visual', 10),
            'size' => $defaults->forAttribute('size', 144, 'select', 'text', 20),
            'price' => $defaults->forBuiltin('price'),
        ];
        $configProvider = $this->createStub(FilterConfigProvider::class);
        $configProvider->method('getActive')->willReturn($configs);
        $slugProvider = $this->createStub(SlugProvider::class);
        $slugProvider->method('forStore')->willReturn([
            'attributes' => [93 => 'color', 144 => 'size'],
            'options' => [93 => [50 => 'red', 49 => 'blue'], 144 => [168 => 'm']],
        ]);

        return new ParamNormalizer(new UrlCodec($configProvider, $slugProvider, new Range(), $this->createStub(CategoryTree::class)));
    }
}
