<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model;

use Iranimij\Base\Model\Config\TypedReader;
use Iranimij\OpenFilter\Model\Coexistence\Detector;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * OpenFilter settings (section `openfilter` under the `iranimij` tab), read through the shared base module.
 */
class Config
{
    public const XML_PATH_ENABLED = 'openfilter/general/enabled';
    public const XML_PATH_DEBUG = 'openfilter/general/debug';
    public const XML_PATH_AJAX = 'openfilter/general/ajax';
    public const XML_PATH_EXPANDED_GROUPS = 'openfilter/display/expanded_groups';
    public const XML_PATH_PRICE_STEP = 'openfilter/display/price_step';
    public const XML_PATH_NOFOLLOW_THRESHOLD = 'openfilter/seo/nofollow_threshold';
    public const XML_PATH_MAX_ACTIVE_VALUES = 'openfilter/seo/max_active_values';
    public const XML_PATH_LAYOUT = 'openfilter/display/layout';
    public const XML_PATH_APPLY_MODE = 'openfilter/display/apply_mode';
    public const XML_PATH_STICKY = 'openfilter/display/sticky';
    public const XML_PATH_QUICK_BAR_SIZE = 'openfilter/display/quick_bar_size';
    public const XML_PATH_PAGINATION = 'openfilter/display/pagination';
    public const XML_PATH_URL_FORMAT = 'openfilter/seo/url_format';
    public const XML_PATH_META_TITLE = 'openfilter/seo/meta_title';
    public const XML_PATH_META_DESCRIPTION = 'openfilter/seo/meta_description';
    public const XML_PATH_H1 = 'openfilter/seo/h1';
    public const XML_PATH_SITEMAP_ENABLED = 'openfilter/sitemap/enabled';
    public const XML_PATH_SITEMAP_MIN_PRODUCTS = 'openfilter/sitemap/min_products';
    public const XML_PATH_SITEMAP_MAX_URLS = 'openfilter/sitemap/max_urls';

    public const LAYOUT_SIDEBAR = 'sidebar';
    public const LAYOUT_HORIZONTAL = 'horizontal';
    public const APPLY_INSTANT = 'instant';
    public const APPLY_BUTTON = 'button';

    /** More products (1.2): the pager only, a "Load more" button, or loading as shoppers scroll. */
    public const PAGINATION_PAGES = 'pages';
    public const PAGINATION_LOAD_MORE = 'load_more';
    public const PAGINATION_INFINITE = 'infinite';

    /** Filter URLs (1.2): query parameters (?color=black) or path segments (/color-black.html) on category pages. */
    public const URL_FORMAT_QUERY = 'query';
    public const URL_FORMAT_PATH = 'path';

    private bool $loggedReplacement = false;

    /**
     * @param TypedReader $reader
     * @param Detector|null $detector another layered navigation (ElasticSuite, Live Search) switches OpenFilter off
     * @param LoggerInterface|null $logger the openfilter channel (debug mode: why OpenFilter steps aside)
     */
    public function __construct(
        private readonly TypedReader $reader,
        private readonly ?Detector $detector = null,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Whether OpenFilter replaces the layered navigation in the given store view (current store when null): enabled
     * in the configuration and no other layered navigation active.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        if (!$this->reader->getBool(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }
        $replacement = $this->detector?->getActiveReplacement();
        if ($replacement !== null && !$this->loggedReplacement && $this->isDebug()) {
            $this->logger?->info(sprintf('OpenFilter steps aside: %s provides the layered navigation.', $replacement));
            $this->loggedReplacement = true;
        }

        return $replacement === null;
    }

    /**
     * Debug mode: data-of-* attributes in the markup and the facet count strategy in var/log/openfilter.log.
     *
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->reader->getBool(self::XML_PATH_DEBUG, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null);
    }

    /**
     * Filter clicks update the page without a reload (links and forms work either way).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAjaxEnabled(?int $storeId = null): bool
    {
        return $this->reader->getBool(self::XML_PATH_AJAX, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Values shown in the quick filter bar above the products (1.2); 0 = no bar.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getQuickBarSize(?int $storeId = null): int
    {
        return max(0, $this->reader->getInt(self::XML_PATH_QUICK_BAR_SIZE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Filter groups open by default when a filter's own setting is "automatic".
     *
     * @param int|null $storeId
     * @return int
     */
    public function getExpandedGroups(?int $storeId = null): int
    {
        return max(0, $this->reader->getInt(self::XML_PATH_EXPANDED_GROUPS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Step of the price slider in the display currency.
     *
     * @param int|null $storeId
     * @return float
     */
    public function getPriceStep(?int $storeId = null): float
    {
        return max(0.0, $this->reader->getFloat(self::XML_PATH_PRICE_STEP, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Option links get rel="nofollow" once this many values are active.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getNofollowThreshold(?int $storeId = null): int
    {
        return max(0, $this->reader->getInt(self::XML_PATH_NOFOLLOW_THRESHOLD, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * More active values than this redirect to the first ones (protects crawl budget and the page cache).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxActiveValues(?int $storeId = null): int
    {
        return max(0, $this->reader->getInt(self::XML_PATH_MAX_ACTIVE_VALUES, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Desktop layout: sidebar (1.0) or a bar above the products (1.1). Unknown values fall back to the sidebar.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLayout(?int $storeId = null): string
    {
        $layout = $this->reader->getString(self::XML_PATH_LAYOUT, ScopeInterface::SCOPE_STORE, $storeId);

        return $layout === self::LAYOUT_HORIZONTAL ? self::LAYOUT_HORIZONTAL : self::LAYOUT_SIDEBAR;
    }

    /**
     * Desktop: results update with every click (1.0) or after "Show N products" (1.1).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApplyMode(?int $storeId = null): string
    {
        $mode = $this->reader->getString(self::XML_PATH_APPLY_MODE, ScopeInterface::SCOPE_STORE, $storeId);

        return $mode === self::APPLY_BUTTON ? self::APPLY_BUTTON : self::APPLY_INSTANT;
    }

    /**
     * Filter URLs of category pages (1.2); search pages always use query parameters.
     *
     * @param int|null $storeId
     * @return string URL_FORMAT_QUERY or URL_FORMAT_PATH
     */
    public function getUrlFormat(?int $storeId = null): string
    {
        $format = $this->reader->getString(self::XML_PATH_URL_FORMAT, ScopeInterface::SCOPE_STORE, $storeId);

        return $format === self::URL_FORMAT_PATH ? self::URL_FORMAT_PATH : self::URL_FORMAT_QUERY;
    }

    /**
     * How shoppers see more products (1.2); the pager's links stay in every mode.
     *
     * @param int|null $storeId
     * @return string one of the PAGINATION_* constants
     */
    public function getPagination(?int $storeId = null): string
    {
        $mode = $this->reader->getString(self::XML_PATH_PAGINATION, ScopeInterface::SCOPE_STORE, $storeId);

        return in_array($mode, [self::PAGINATION_LOAD_MORE, self::PAGINATION_INFINITE], true) ? $mode : self::PAGINATION_PAGES;
    }

    /**
     * The sidebar stays in view while the shopper scrolls the products (1.1).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSticky(?int $storeId = null): bool
    {
        return $this->reader->getBool(self::XML_PATH_STICKY, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Template of the page title of filtered pages ({category}, {filters}, {store}); '' = Magento's title (1.1).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getMetaTitleTemplate(?int $storeId = null): string
    {
        return trim($this->reader->getString(self::XML_PATH_META_TITLE, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Template of the meta description of filtered pages; '' = Magento's description (1.1).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getMetaDescriptionTemplate(?int $storeId = null): string
    {
        return trim($this->reader->getString(self::XML_PATH_META_DESCRIPTION, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Template of the H1 of filtered pages; '' = the category name (1.1).
     *
     * @param int|null $storeId
     * @return string
     */
    public function getH1Template(?int $storeId = null): string
    {
        return trim($this->reader->getString(self::XML_PATH_H1, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Indexable filter pages are added to Magento's XML sitemap (1.1).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isSitemapEnabled(?int $storeId = null): bool
    {
        return $this->reader->getBool(self::XML_PATH_SITEMAP_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * A filter page goes into the sitemap only with at least this many products (1.1).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getSitemapMinProducts(?int $storeId = null): int
    {
        return max(1, $this->reader->getInt(self::XML_PATH_SITEMAP_MIN_PRODUCTS, ScopeInterface::SCOPE_STORE, $storeId));
    }

    /**
     * Most filter pages per store view in the sitemap (1.1).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getSitemapMaxUrls(?int $storeId = null): int
    {
        return max(0, $this->reader->getInt(self::XML_PATH_SITEMAP_MAX_URLS, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
