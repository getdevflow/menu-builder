<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Support;

use Plugin\MenuBuilder\Domain\Menu;
use Plugin\MenuBuilder\Domain\MenuItem;
use Qubus\Exception\Data\TypeException;
use Spatie\Menu\Html;
use Spatie\Menu\Link;
use Spatie\Menu\Menu as SpatieMenu;

final readonly class NavigationRenderer
{
    /**
     * @throws TypeException
     */
    public function render(Menu $menu, array $options = []): string
    {
        $version = (int) ($options['bootstrap'] ?? 3);

        $toggleAttribute = match ($version) {
            5 => 'data-bs-toggle',
            default => 'data-toggle',
        };

        $spatie = SpatieMenu::new()
            ->addClass($options['menu_class'] ?? 'nav navbar-nav');

        foreach ($menu->rootItems() as $item) {
            $spatie->add(
                $this->renderItem(
                    item: $item,
                    bootstrapVersion: $version,
                    toggleAttribute: $toggleAttribute
                )
            );
        }

        return $spatie->render();
    }

    /**
     * @throws TypeException
     */
    private function renderItem(
        MenuItem $item,
        int $bootstrapVersion,
        string $toggleAttribute
    ): mixed {
        if (!$item->hasChildren()) {
            return Link::to($item->url(), $item->label())
                ->addClass($item->cssClass())
                ->setAttributes($item->attributes());
        }

        $children = SpatieMenu::new()
            ->addClass('dropdown-menu');

        foreach ($item->children() as $child) {
            $children->add(
                $this->renderItem(
                    item: $child,
                    bootstrapVersion: $bootstrapVersion,
                    toggleAttribute: $toggleAttribute
                )
            );
        }

        $caret = $bootstrapVersion === 3
            ? ' <span class="caret"></span>'
            : '';

        return Html::raw(
            '<li class="dropdown">' .
                '<a href="' . htmlspecialchars($item->url() ?: '#', ENT_QUOTES, 'UTF-8') . '" ' .
                'class="dropdown-toggle" ' .
                $toggleAttribute . '="dropdown" role="button">' .
                htmlspecialchars($item->label(), ENT_QUOTES, 'UTF-8') .
                $caret .
                '</a>' .
                $children->render() .
            '</li>'
        );
    }
}
