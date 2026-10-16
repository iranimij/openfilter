<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Layer;

use Iranimij\OpenFilter\Api\FilterStateInterface;
use Iranimij\OpenFilter\Api\UrlCodecInterface;
use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Config\FilterConfig;
use Iranimij\OpenFilter\Model\Config\FilterConfigProvider;
use Iranimij\OpenFilter\Model\Url\FilterStateProvider;
use Iranimij\OpenFilter\Model\Url\PathUrl;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What the layer filters of one storefront request share: whether OpenFilter runs, the store's filter settings,
 * the decoded filter state and canonical URLs for other states.
 */
class LayerContext
{
    /** Parameters dropped from filter links: a new filter state starts at page 1. */
    private const DROPPED_PARAMS = ['p'];

    /**
     * @param Config $config
     * @param FilterConfigProvider $configProvider
     * @param FilterStateProvider $stateProvider
     * @param UrlCodecInterface $codec
     * @param StoreManagerInterface $storeManager
     * @param RequestInterface $request
     * @param UrlInterface $url
     * @param PathUrl $pathUrl path filter URLs of category pages (1.2, opt-in)
     */
    public function __construct(
        private readonly Config $config,
        private readonly FilterConfigProvider $configProvider,
        private readonly FilterStateProvider $stateProvider,
        private readonly UrlCodecInterface $codec,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly UrlInterface $url,
        private readonly PathUrl $pathUrl
    ) {
    }

    /**
     * Whether OpenFilter handles layered navigation in the current store view.
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->config->isEnabled($this->storeId());
    }

    /**
     * @return int
     */
    public function storeId(): int
    {
        return (int) $this->storeManager->getStore()->getId();
    }

    /**
     * @return FilterStateInterface
     */
    public function state(): FilterStateInterface
    {
        return $this->stateProvider->get();
    }

    /**
     * Provider key of an attribute's filter, or null when the attribute is not an active filter.
     *
     * @param int $attributeId
     * @return string|null
     */
    public function keyForAttribute(int $attributeId): ?string
    {
        foreach ($this->configProvider->getActive($this->storeId()) as $key => $config) {
            if (!$config->isBuiltin() && $config->getAttributeId() === $attributeId) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Whether the merchant hid this filter in Catalog > OpenFilter > Filters (an attribute by id, or the price or
     * category built-in by code). Unknown filters are not hidden: they stay with core.
     *
     * @param int|null $attributeId
     * @param string|null $builtinCode
     * @return bool
     */
    public function isHidden(?int $attributeId, ?string $builtinCode = null): bool
    {
        foreach ($this->configProvider->getForStore($this->storeId()) as $config) {
            $match = $builtinCode !== null
                ? $config->isBuiltin() && $config->getCode() === $builtinCode
                : !$config->isBuiltin() && $config->getAttributeId() === $attributeId;
            if ($match) {
                return !$config->isActive();
            }
        }

        return false;
    }

    /**
     * Provider key of an attribute's filter by attribute code.
     *
     * @param string $attributeCode
     * @return string|null
     */
    public function keyForAttributeCode(string $attributeCode): ?string
    {
        foreach ($this->configProvider->getActive($this->storeId()) as $key => $config) {
            if (!$config->isBuiltin() && $config->getCode() === $attributeCode) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Link that selects a value (adds it, or replaces it in single-select mode), or deselects it when selected.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    public function toggleUrl(string $key, string $value): string
    {
        $state = $this->state();
        $current = $state->get($key);
        if (in_array($value, $current, true)) {
            return $this->removeUrl($key, $value);
        }
        $multiselect = $this->config($key)?->isMultiselect() ?? false;

        return $this->urlFor($state->with($key, $multiselect ? [...$current, $value] : [$value]));
    }

    /**
     * Link that removes one value of a filter.
     *
     * @param string $key
     * @param string $value
     * @return string
     */
    public function removeUrl(string $key, string $value): string
    {
        $state = $this->state();

        return $this->urlFor($state->with($key, array_values(array_diff($state->get($key), [$value]))));
    }

    /**
     * @param string $key
     * @return FilterConfig|null
     */
    public function config(string $key): ?FilterConfig
    {
        return $this->configProvider->getActive($this->storeId())[$key] ?? null;
    }

    /**
     * URL parameter of a filter.
     *
     * @param string $key
     * @return string|null
     */
    public function parameterFor(string $key): ?string
    {
        $param = array_search($key, $this->codec->getParameterNames($this->storeId()), true);

        return $param === false ? null : (string) $param;
    }

    /**
     * Canonical URL of the current page with another filter state; sort, limit and mode stay, the page resets.
     *
     * @param FilterStateInterface $state
     * @param array<string, string|int|null> $override other parameters to set (null removes), e.g. a core filter's
     * @return string
     */
    public function urlFor(FilterStateInterface $state, array $override = []): string
    {
        $storeId = $this->storeId();
        $keep = [];
        $query = $this->request instanceof HttpRequest ? $this->request->getQuery()->toArray() : [];
        $filterParams = $this->codec->getParameterNames($storeId);
        foreach ($query as $name => $value) {
            if (is_scalar($value) && !isset($filterParams[$name]) && !in_array($name, self::DROPPED_PARAMS, true)) {
                $keep[(string) $name] = (string) $value;
            }
        }
        foreach ($override as $name => $value) {
            unset($keep[$name]);
            if ($value !== null && $value !== '') {
                $keep[$name] = (string) $value;
            }
        }
        // _current keeps route parameters (catalog/category/view/id/12 without a URL rewrite); the query goes.
        $base = $this->url->getUrl('*/*/*', ['_use_rewrite' => true, '_current' => true]);
        $base = strtok($base, '?') ?: $base;
        if ($this->isCategoryPage() && $this->pathUrl->isActive($storeId)) {
            return $this->pathUrl->build($state, $base, $keep, $storeId);
        }

        return $this->codec->urlFor($state, $base, $keep, $storeId);
    }

    /**
     * A category page (path filter URLs apply there, not on search pages or the All Products page).
     *
     * @return bool
     */
    public function isCategoryPage(): bool
    {
        return $this->request instanceof HttpRequest && $this->request->getFullActionName() === 'catalog_category_view';
    }
}
