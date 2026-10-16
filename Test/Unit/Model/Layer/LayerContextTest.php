<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Model\Layer;

use Iranimij\OpenFilter\Model\Url\PathUrl;
use Iranimij\OpenFilter\Api\UrlCodecInterface;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Config\Defaults;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Layer\LayerContext;
use Iranimij\OpenFilter\Model\Url\FilterState;
use Iranimij\OpenFilter\Model\Url\FilterStateProvider;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class LayerContextTest extends TestCase
{
    public function testLookupsAndCanonicalUrlKeepingSortAndDroppingPage(): void
    {
        $defaults = new Defaults();
        $configProvider = $this->createStub(FilterConfigProvider::class);
        $configProvider->method('getForStore')->willReturn([
            'category' => $defaults->forBuiltin('category')->with(['active' => false]),
            'price' => $defaults->forBuiltin('price')->with(['attribute_id' => 77]),
            'color' => $defaults->forAttribute('color', 93, 'select', 'visual', 10),
            'size' => $defaults->forAttribute('size', 144, 'select', 'text', 20)->with(['active' => false]),
        ]);
        $configProvider->method('getActive')->willReturn([
            'price' => $defaults->forBuiltin('price')->with(['attribute_id' => 77]),
            'color' => $defaults->forAttribute('color', 93, 'select', 'visual', 10),
        ]);
        $state = new FilterState(['color' => ['50']]);
        $stateProvider = $this->createStub(FilterStateProvider::class);
        $stateProvider->method('get')->willReturn($state);
        $codec = $this->createStub(UrlCodecInterface::class);
        $codec->method('getParameterNames')->willReturn(['farbe' => 'color', 'price' => 'price']);
        $codec->method('urlFor')->willReturnCallback(
            static fn ($s, string $base, array $keep, int $store): string =>
                $base . '|' . json_encode($s->getValues()) . '|' . json_encode($keep) . '|' . $store
        );
        $request = $this->createStub(HttpRequest::class);
        $request->method('getQuery')->willReturn(
            new Parameters(['farbe' => 'rot', 'p' => '3', 'product_list_order' => 'price', 'q' => 'tee', 'x' => ['a']])
        );
        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturn('https://shop.test/women.html?farbe=rot');
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $config = $this->createStub(Config::class);
        $config->method('isEnabled')->willReturn(true);

        $context = new LayerContext($config, $configProvider, $stateProvider, $codec, $storeManager, $request, $url, $this->createStub(PathUrl::class));

        self::assertTrue($context->isActive());
        self::assertTrue($context->isHidden(144, null), 'size hidden by the merchant');
        self::assertFalse($context->isHidden(93, null));
        self::assertFalse($context->isHidden(500, null), 'not an OpenFilter filter: left to core');
        self::assertTrue($context->isHidden(null, 'category'));
        self::assertFalse($context->isHidden(null, 'price'));
        self::assertSame('color', $context->keyForAttribute(93));
        self::assertNull($context->keyForAttribute(77), 'price is a built-in, not an attribute filter');
        self::assertSame('farbe', $context->parameterFor('color'));
        self::assertNull($context->parameterFor('size'));
        self::assertSame($state, $context->state());
        self::assertNotNull($context->config('color'));
        self::assertSame(
            'https://shop.test/women.html|{"color":["50"]}|{"product_list_order":"price","q":"tee"}|2',
            $context->urlFor($state)
        );
        self::assertSame(
            'https://shop.test/women.html|{"color":["50"]}|{"q":"tee","cat":"12"}|2',
            $context->urlFor($state, ['product_list_order' => null, 'cat' => 12])
        );
        self::assertSame('color', $context->keyForAttributeCode('color'));
        self::assertNull($context->keyForAttributeCode('price'));
        self::assertStringContainsString('{"color":["50","49"]}', $context->toggleUrl('color', '49'));
        self::assertStringContainsString('|[]|', str_replace('{}', '[]', $context->toggleUrl('color', '50')));
        self::assertStringContainsString('{"color":["50"]}', $context->removeUrl('color', '49'));
    }
}
