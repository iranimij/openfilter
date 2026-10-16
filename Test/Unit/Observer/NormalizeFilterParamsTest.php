<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Test\Unit\Observer;

use Iranimij\OpenFilter\Model\Url\PathUrl;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Layer\LayerContext;
use Iranimij\OpenFilter\Model\Seo\MaxFiltersGuard;
use Iranimij\OpenFilter\Model\Url\FilterState;
use Iranimij\OpenFilter\Model\Url\ParamNormalizer;
use Iranimij\OpenFilter\Observer\NormalizeFilterParams;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class NormalizeFilterParamsTest extends TestCase
{
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = new NullLogger();
    }

    public function testRedirectsWithThePageKeptWhenOnlyTheOrderChanged(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setRedirect')->with('canonical|{"p":"2"}', 301);
        $flag = $this->createMock(ActionFlag::class);
        $flag->expects(self::once())->method('set')->with('', ActionInterface::FLAG_NO_DISPATCH, '1');

        $this->observer(['redirect' => true, 'changed' => false], $response, $flag)
            ->execute($this->event($this->request('GET', ['color' => 'blue,red', 'p' => '2'])));
    }

    public function testPageResetsWhenValuesWereDropped(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setRedirect')->with('canonical|[]', 301);

        $this->observer(['redirect' => true, 'changed' => true], $response, $this->createStub(ActionFlag::class))
            ->execute($this->event($this->request('GET', ['color' => 'junk', 'p' => '2'])));
    }

    public function testFoldedRangeFormFieldsLeaveTheRedirectUrl(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setRedirect')
            ->with('canonical|{"price_from":null,"price_to":null}', 301);

        $this->observer(
            ['redirect' => true, 'changed' => false, 'drop' => ['price_from', 'price_to']],
            $response,
            $this->createStub(ActionFlag::class)
        )->execute($this->event($this->request('GET', ['price_from' => '20', 'price_to' => '50'])));
    }

    public function testCanonicalPostAndInactiveAreLeftAlone(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::never())->method('setRedirect');
        $flag = $this->createStub(ActionFlag::class);

        $this->observer(['redirect' => false, 'changed' => false], $response, $flag)
            ->execute($this->event($this->request('GET', ['color' => 'red'])));
        $this->observer(['redirect' => true, 'changed' => false], $response, $flag)
            ->execute($this->event($this->request('POST', ['color' => 'blue,red'])));
        $this->observer(['redirect' => true, 'changed' => false], $response, $flag, false)
            ->execute($this->event($this->request('GET', ['color' => 'blue,red'])));
        $this->observer(['redirect' => true, 'changed' => false], $response, $flag)
            ->execute(new Observer(['event' => new Event(['request' => null])]));
    }

    public function testTooManyValuesRedirectTemporarilyToTheFirstOnesWithoutIndexing(): void
    {
        $response = $this->createMock(HttpResponse::class);
        $response->expects(self::once())->method('setRedirect')
            ->with('canonical|{"price_from":null,"price_to":null,"p":null}', 302);
        $response->expects(self::once())->method('setHeader')->with('X-Robots-Tag', 'noindex', true);
        $flag = $this->createMock(ActionFlag::class);
        $flag->expects(self::once())->method('set')->with('', ActionInterface::FLAG_NO_DISPATCH, '1');

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())->method('info')
            ->with(self::stringContains('max values guard: 1 values requested, 302 to canonical|'));
        // Even a canonical URL is cut: the guard comes before the normaliser, one hop to the final URL.
        $this->observer(
            ['redirect' => false, 'changed' => false, 'drop' => ['price_from', 'price_to']],
            $response,
            $flag,
            true,
            new FilterState(['color' => ['1']])
        )->execute($this->event($this->request('GET', ['color' => 'a,b,c,d,e,f', 'p' => '2'])));
    }

    /**
     * @param array{redirect: bool, changed: bool} $result
     */
    private function observer(
        array $result,
        HttpResponse $response,
        ActionFlag $flag,
        bool $active = true,
        ?FilterState $truncated = null
    ): NormalizeFilterParams {
        $normalizer = $this->createStub(ParamNormalizer::class);
        $normalizer->method('check')->willReturn($result + ['state' => new FilterState(['color' => ['1']])]);
        $context = $this->createStub(LayerContext::class);
        $context->method('isActive')->willReturn($active);
        $context->method('urlFor')->willReturnCallback(
            static fn ($state, array $override = []): string => 'canonical|' . json_encode($override)
        );

        $guard = $this->createStub(MaxFiltersGuard::class);
        $guard->method('truncate')->willReturn($truncated);

        $config = $this->createStub(Config::class);
        $config->method('isDebug')->willReturn(true);

        return new NormalizeFilterParams($normalizer, $context, $flag, $response, $guard, $config, $this->logger, $this->createStub(PathUrl::class));
    }

    /**
     * @param array<string, mixed> $query
     */
    private function request(string $method, array $query): HttpRequest
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isGet')->willReturn($method === 'GET');
        $request->method('isHead')->willReturn(false);
        $request->method('getQuery')->willReturnCallback(
            static fn (?string $name = null) => $name === null ? new Parameters($query) : ($query[$name] ?? null)
        );

        return $request;
    }

    private function event(HttpRequest $request): Observer
    {
        return new Observer(['event' => new Event(['request' => $request])]);
    }
}
