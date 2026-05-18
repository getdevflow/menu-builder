<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Controller;

use Exception;
use Plugin\MenuBuilder\Repository\NavigationRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Qubus\Http\Factories\JsonResponseFactory;
use Throwable;

use function Codefy\Framework\Helpers\view;
use function json_decode;

final readonly class NavigationController
{
    public function __construct(private NavigationRepository $navigation)
    {
    }

    /**
     * @throws Exception
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $menus = $this->navigation->menus();
        $selected = (string) ($request->getQueryParams()['menu'] ?? ($menus[0]['id'] ?? ''));
        $menu = $selected !== '' ? $this->navigation->findMenu($selected) : null;

        return view('plugin::MenuBuilder/view/index', [
            'menus' => $menus,
            'selected' => $selected,
            'menu' => $menu,
            'available' => $this->navigation->availableItems(),
        ]);
    }

    /**
     * @throws Exception
     */
    public function createMenu(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $id = $this->navigation->createMenu(trim((string) ($data['name'] ?? 'New Menu')));
        return $this->json(['success' => true, 'id' => $id]);
    }

    /**
     * @throws Exception
     */
    public function updateMenu(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $this->navigation->updateMenu((string) $data['menu_id'], trim((string) $data['name']));
        return $this->json(['success' => true]);
    }

    /**
     * @throws Exception
     */
    public function deleteMenu(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $this->navigation->deleteMenu((string) $data['menu_id']);
        return $this->json(['success' => true]);
    }

    /**
     * @throws Exception
     */
    public function addItem(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $id = $this->navigation->addItem((string) $data['menu_id'], $data);
        return $this->json(['success' => true, 'id' => $id]);
    }

    /**
     * @throws Exception
     */
    public function updateItem(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $this->navigation->updateItem((string) $data['item_id'], $data);
        return $this->json(['success' => true]);
    }

    /**
     * @throws Exception
     */
    public function removeItem(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $this->navigation->removeItem((string) $data['item_id']);
        return $this->json(['success' => true]);
    }

    /**
     * @throws Throwable
     */
    public function reorder(ServerRequestInterface $request): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $items = json_decode((string) ($data['items'] ?? '[]'), true) ?: [];
        $this->navigation->reorder((string) $data['menu_id'], $items);
        return $this->json(['success' => true]);
    }

    /**
     * @throws Exception
     */
    private function json(array $payload): ResponseInterface
    {
        return JsonResponseFactory::create($payload);
    }
}
