<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Helper;

use App\Application\Devflow;
use Plugin\MenuBuilder\Repository\NavigationRepository;
use Plugin\MenuBuilder\Support\NavigationRenderer;
use Qubus\Exception\Data\TypeException;

final class NavMenu
{
    /**
     * @throws TypeException
     */
    public static function render(string $slug, array $options = []): string
    {
        /** @var NavigationRepository $repo */
        $repo = Devflow::$PHP->make(name: NavigationRepository::class);

        /** @var NavigationRenderer $renderer */
        $renderer = Devflow::$PHP->make(name: NavigationRenderer::class);

        $menu = $repo->findMenu($slug);

        if (!$menu) {
            return '';
        }

        return $renderer->render($menu, $options);
    }
}
