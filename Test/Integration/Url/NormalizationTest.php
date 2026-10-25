<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Integration\Url;

use Iranimij\OpenFilter\Model\Coexistence\Detector;
use Iranimij\OpenFilter\Test\Integration\Catalog\M1Catalog;
use Magento\Framework\Notification\MessageList;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Non-canonical filter URLs answer 301 through the real front controller; canonical ones render.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 */
class NormalizationTest extends AbstractController
{
    /** @var array{category: int, store: int, options: array<string, array<string, int>>} */
    private static ?array $catalog = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$catalog = M1Catalog::create();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function redirects(): array
    {
        $color = M1Catalog::COLOR;
        $size = M1Catalog::SIZE;

        return [
            'value order' => [[$color => 'blue,red', 'p' => '2'], "?$color=red,blue&p=2"],
            'parameter order and a value, sort kept' => [[$size => 'M', 'product_list_order' => 'name', $color => 'red'], "?$color=red&$size=m&product_list_order=name"],
            'upper case' => [[$color => 'RED'], "?$color=red"],
            'junk resets the page' => [[$color => 'red,nonsense', 'p' => '3'], "?$color=red"],
            'only junk' => [[$color => 'nonsense'], ''],
            'array syntax' => [[$color => ['blue', 'red']], "?$color=red,blue"],
            'range form without JavaScript' => [[$color => 'red', 'price_from' => '20', 'price_to' => '40'], "?$color=red&price=20-40"],
            'open range form' => [['price_from' => '', 'price_to' => '40'], '?price=-40'],
            'empty range form' => [[$color => 'red', 'price_from' => '', 'price_to' => ''], "?$color=red"],
        ];
    }

    /**
     * @dataProvider redirects
     * @param array<string, mixed> $query
     * @param string $expectedQuery
     */
    #[DataProvider('redirects')]
    public function testNonCanonicalFilterUrlsRedirectPermanently(array $query, string $expectedQuery): void
    {
        $this->getRequest()->setMethod('GET')->setQueryValue($query);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertSame(301, $this->getResponse()->getHttpResponseCode());
        $location = (string) $this->getResponse()->getHeader('Location')->getFieldValue();
        self::assertStringEndsWith('catalog/category/view/id/' . self::$catalog['category'] . '/' . $expectedQuery, $location);
    }

    /**
     * Magento's Varnish configuration sorts query parameters before Magento sees them: a redirect to the filters' own
     * order would come back sorted and loop, so the order alone never redirects (the canonical tag names one URL).
     */
    public function testTheParameterOrderAloneDoesNotRedirect(): void
    {
        $this->getRequest()->setMethod('GET')->setQueryValue([M1Catalog::SIZE => 'm', M1Catalog::COLOR => 'red']);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
    }

    public function testOptionIdsBecomeUrlNames(): void
    {
        $id = self::$catalog['options'][M1Catalog::COLOR]['Green'];
        $this->getRequest()->setMethod('GET')->setQueryValue([M1Catalog::COLOR => (string) $id]);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertSame(301, $this->getResponse()->getHttpResponseCode());
        self::assertStringEndsWith(
            '?' . M1Catalog::COLOR . '=green',
            (string) $this->getResponse()->getHeader('Location')->getFieldValue()
        );
    }

    public function testCanonicalUrlRendersTheFilteredPage(): void
    {
        $this->getRequest()->setMethod('GET')->setQueryValue([M1Catalog::COLOR => 'red,blue', 'p' => '1']);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        $body = (string) $this->getResponse()->getBody();
        self::assertStringContainsString(M1Catalog::COLOR . '=red,blue,green', html_entity_decode($body));
    }

    public function testPostIsNeverRedirected(): void
    {
        $this->getRequest()->setMethod('POST')->setQueryValue([M1Catalog::COLOR => 'blue,red']);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertNotSame(301, $this->getResponse()->getHttpResponseCode());
    }

    public function testAnotherLayeredNavigationSwitchesOpenFilterOffWithANotice(): void
    {
        $om = Bootstrap::getObjectManager();
        // Simulates an active ElasticSuite: any enabled module stands in for it.
        $om->configure([Detector::class => ['arguments' => ['replacements' => ['Magento_Catalog' => 'ElasticSuite']]]]);
        $this->getRequest()->setMethod('GET')->setQueryValue([M1Catalog::COLOR => 'blue,red']);
        $this->dispatch('catalog/category/view/id/' . self::$catalog['category']);

        self::assertSame(200, $this->getResponse()->getHttpResponseCode(), 'no OpenFilter redirect');
        self::assertStringNotContainsString(M1Catalog::COLOR . '=red,blue,', html_entity_decode((string) $this->getResponse()->getBody()));
        Bootstrap::getInstance()->loadArea('adminhtml');
        $messages = (array) $om->create(MessageList::class)->asArray();
        $texts = array_map(static fn ($m): string => (string) $m->getText(), $messages);
        self::assertNotEmpty(array_filter($texts, static fn (string $t): bool => str_contains($t, 'ElasticSuite')));
    }
}
