<?php

namespace Plugin\LoyaltyPoints\Sections\General;

use Plugin\LoyaltyPoints\Backend\Support\PointsBadge;

/**
 * The page-builder section wrapper.
 *
 * `section.blade.php` finds this file by path and instantiates the class whose name it
 * derives from the section type, so this must live here and be called `PointsBadgePlugin`.
 * That path is outside the plugin autoloader's `Plugin\{Ns}\` → package-root mapping, which
 * means the class only exists while a section is rendering.
 *
 * The account-page block needs the same output without a section, so the work lives in
 * `Backend\Support\PointsBadge` — which does autoload — and this is the seam that lets the
 * builder reach it.
 */
class PointsBadgePlugin
{
    public function __construct(private PointsBadge $badge)
    {
    }

    /** @param array<string,mixed> $data */
    public function render(array $data, string $locale, string $viewPath): string
    {
        return $this->badge->render($data, $locale, $viewPath);
    }
}
