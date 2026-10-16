<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Controller;

use Iranimij\OpenFilter\Model\Config;
use Iranimij\OpenFilter\Model\Url\PathUrl;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Path filter URLs (1.2): takes the filter segments off the end of a category path (women/tops-women/color-black.html
 * → women/tops-women.html with color=black) and lets Magento's URL rewrite router, which runs next, find the
 * category. Matches nothing itself, so the category costs the same one URL rewrite lookup as without filters; the
 * layer reads the filters as query parameters as in the query format.
 */
class PathRouter implements RouterInterface
{
    /**
     * @param Config $config
     * @param PathUrl $pathUrl
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Config $config,
        private readonly PathUrl $pathUrl,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest || $request->getModuleName() || $request->getAlias(PathUrl::ALIAS) !== null) {
            return null;
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isEnabled($storeId) || !$this->pathUrl->isActive($storeId)) {
            return null;
        }
        $parsed = $this->pathUrl->parse(trim((string) $request->getPathInfo(), '/'), $storeId);
        if ($parsed === null) {
            return null;
        }
        [$path, $params, $taken] = $parsed;
        $request->setPathInfo('/' . $path);
        foreach ($params as $name => $value) {
            if ($request->getQuery($name) === null) {
                $request->setQueryValue($name, $value);
            }
        }
        $request->setAlias(PathUrl::ALIAS, $taken);

        return null;
    }
}
