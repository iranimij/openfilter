<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Integration\Url;

use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Test\Integration\Catalog\M1Catalog;
use Iranimij\OpenFilter\Test\Integration\Search\EngineRequests;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * M6 S6 path filter URLs: a path page lists the products of its query twin in one search request, query links and
 * segments in another order redirect (301) to the canonical path, a segment that is no filter stays a 404.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 */
class PathUrlsTest extends AbstractController
{
    private const COLOR = M1Catalog::COLOR;
    private const SIZE = M1Catalog::SIZE;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        M1Catalog::create();
    }

    /**
     * @return string[]
     */
    public function testTheQueryTwin(): array
    {
        $this->dispatch('/of-m1.html?' . self::COLOR . '=red');
        $products = $this->products();
        self::assertNotSame([], $products);

        return $products;
    }

    /**
     * @depends testTheQueryTwin
     * @param string[] $query
     */
    #[\PHPUnit\Framework\Attributes\Depends('testTheQueryTwin')]
    public function testAPathPageListsTheProductsOfItsQueryTwin(array $query): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $this->dispatch('/of-m1/' . self::COLOR . '-red.html');

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame($query, $this->products());
        self::assertMatchesRegularExpression('#<link\s+rel="canonical"\s+href="[^"]*/of-m1(?:/' . self::COLOR . '-red)?\.html"#', (string) $this->getResponse()->getBody());
    }

    public function testAQueryLinkRedirectsToThePath(): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $this->getRequest()->setMethod('GET')->setQueryValue([self::COLOR => 'red', 'p' => '2']);
        $this->dispatch('/of-m1.html');

        self::assertSame(301, $this->getResponse()->getHttpResponseCode());
        self::assertStringEndsWith('/of-m1/' . self::COLOR . '-red.html?p=2', $this->location());
    }

    public function testSegmentsInAnotherOrderRedirectToTheCanonicalOrder(): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $this->dispatch('/of-m1/' . self::SIZE . '-m/' . self::COLOR . '-red.html');

        self::assertSame(301, $this->getResponse()->getHttpResponseCode());
        self::assertStringEndsWith('/of-m1/' . self::COLOR . '-red/' . self::SIZE . '-m.html', $this->location());
    }

    public function testASegmentThatIsNoFilterIsA404(): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $this->dispatch('/of-m1/nothing-red.html');

        self::assertSame(404, $this->getResponse()->getHttpResponseCode());
    }

    public function testThreeFiltersInThePathCostOneSearchRequest(): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $requests = EngineRequests::during(fn () => $this->dispatch(
            '/of-m1/' . self::COLOR . '-red,blue/' . M1Catalog::MATERIAL . '-cotton/' . self::SIZE . '-m.html'
        ));

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertSame(1, $requests);
    }

    public function testTheQueryTwinsSqlQueries(): int
    {
        return $this->sqlQueries('/of-m1.html?' . self::COLOR . '=red,blue&' . self::SIZE . '=m');
    }

    /**
     * @depends testTheQueryTwinsSqlQueries
     */
    #[\PHPUnit\Framework\Attributes\Depends('testTheQueryTwinsSqlQueries')]
    public function testThePathCostsNoMoreSqlThanItsQueryTwin(int $query): void
    {
        $this->format(Config::URL_FORMAT_PATH);
        $path = $this->sqlQueries('/of-m1/' . self::COLOR . '-red,blue/' . self::SIZE . '-m.html');

        self::assertSame(200, $this->getResponse()->getHttpResponseCode());
        self::assertLessThanOrEqual($query, $path, 'the path router reads only cached settings');
    }

    private function sqlQueries(string $url): int
    {
        // The tests before warm the caches; one dispatch per test (Magento's registry allows one category).
        $profiler = Bootstrap::getObjectManager()->get(\Magento\Framework\App\ResourceConnection::class)->getConnection()->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        $this->dispatch($url);
        $queries = (int) $profiler->getTotalNumQueries();
        $profiler->setEnabled(false);

        return $queries;
    }

    private function format(string $format): void
    {
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_PATH_URL_FORMAT, $format, 'store', 'default');
    }

    /**
     * @return string[] product names on the page, sorted
     */
    private function products(): array
    {
        preg_match_all('#<a class="product-item-link"[^>]*>\s*([^<]+?)\s*</a>#', (string) $this->getResponse()->getBody(), $names);
        $names = $names[1];
        sort($names);

        return $names;
    }

    private function location(): string
    {
        return (string) $this->getResponse()->getHeader('Location')->getFieldValue();
    }
}
