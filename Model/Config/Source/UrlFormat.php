<?php
/**
 * Copyright © Iman Aboheydary. All rights reserved.
 * See LICENSE for license details (MIT).
 */

declare(strict_types=1);

namespace Iranimij\OpenFilter\Model\Config\Source;

use Iranimij\OpenFilter\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Options for "Filter URLs on category pages" (1.2).
 */
class UrlFormat implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Config::URL_FORMAT_QUERY, 'label' => __('Query parameters (?color=black)')],
            ['value' => Config::URL_FORMAT_PATH, 'label' => __('Paths (/color-black.html)')],
        ];
    }
}
