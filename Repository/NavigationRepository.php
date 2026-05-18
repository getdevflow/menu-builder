<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Repository;

use Cocur\Slugify\Slugify;
use JsonException;
use PDO;
use Plugin\MenuBuilder\Domain\Menu;
use Plugin\MenuBuilder\Domain\MenuItem;
use Plugin\MenuBuilder\Support\Str;
use Qubus\Exception\Data\TypeException;
use Qubus\Expressive\Database;
use Qubus\ValueObjects\Identity\Ulid;
use Throwable;

use function Codefy\Framework\Helpers\config;

final readonly class NavigationRepository
{
    public function __construct(private Database $dfdb)
    {
    }

    /** @return list<array{id:string,name:string,slug:string,description:string|null}> */
    public function menus(): array
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->query("SELECT menu_id AS id, menu_name AS name, menu_slug AS slug, menu_description AS description FROM {$this->dfdb->prefix}navigation_menu ORDER BY menu_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function createMenu(string $name, ?string $description = null): string
    {
        $id = Ulid::generateAsString();
        $baseSlug = Str::slug($name);
        $slug = $this->uniqueMenuSlug($baseSlug);

        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("INSERT INTO {$this->dfdb->prefix}navigation_menu (menu_id, menu_name, menu_slug, menu_description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$id, $name, $slug, $description]);

        return $id;
    }

    public function updateMenu(string $menuId, string $name, ?string $description = null): void
    {
        $name = trim($name);
        $slug = $this->uniqueMenuSlug($name, $menuId);

        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("UPDATE {$this->dfdb->prefix}navigation_menu SET menu_name = ?, menu_slug = ?, menu_description = ? WHERE menu_id = ?");
        $stmt->execute([$name, $slug, $description, $menuId]);
    }

    public function deleteMenu(string $menuId): void
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("DELETE FROM {$this->dfdb->prefix}navigation_menu WHERE menu_id = ?");
        $stmt->execute([$menuId]);
    }

    public function findMenu(string $menuIdOrSlug): ?Menu
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("SELECT * FROM {$this->dfdb->prefix}navigation_menu WHERE menu_id = ? OR menu_slug = ? LIMIT 1");
        $stmt->execute([$menuIdOrSlug, $menuIdOrSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new Menu(
            id: $row['menu_id'],
            name: $row['menu_name'],
            slug: $row['menu_slug'],
            description: $row['menu_description'],
            items: $this->tree($row['menu_id'])
        );
    }

    /** @return list<MenuItem> */
    public function tree(string $menuId): array
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare(
                "SELECT ni.*,p.title,p.route,pr.product_title,pr.product_slug,c.content_title,c.content_slug
                 FROM {$this->dfdb->prefix}navigation_item ni
                 LEFT JOIN {$this->dfdb->prefix}page_translations p
                 ON p.page_id = ni.object_id
                 LEFT JOIN {$this->dfdb->prefix}product pr
                 ON pr.product_id = ni.object_id
                 LEFT JOIN {$this->dfdb->prefix}content c
                 ON c.content_id = ni.object_id
                 WHERE ni.menu_id = ?
                 ORDER BY ni.parent_id ASC, ni.item_position ASC"
            );

        $stmt->execute([$menuId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        /** @var array<string, MenuItem> $items */
        $items = [];

        foreach ($rows as $row) {
            $resolvedTitle = match ($row['item_type']) {
                'page' => $row['title'] ?? null,
                'product' => $row['product_title'] ?? null,
                'content' => $row['content_title'] ?? null,
                default => null,
            };

            $resolvedSlug = match ($row['item_type']) {
                'page' => $row['route'] ?? null,
                'product' => $row['product_slug'] ?? null,
                'content' => $row['content_slug'] ?? null,
                default => null,
            };

            $item = MenuItem::fromArray([
                ...$row,
                'resolved_title' => $resolvedTitle,
                'resolved_slug' => $resolvedSlug,
            ]);

            $items[$item->id()] = $item;
        }

        $tree = [];

        foreach ($items as $item) {
            $parentId = $item->parentId();

            if ($parentId !== null && isset($items[$parentId])) {
                $items[$parentId]->addChild($item);
                continue;
            }

            $tree[] = $item;
        }

        return $tree;
    }

    /**
     * @throws JsonException
     */
    public function addItem(string $menuId, array $payload): string
    {
        $id = Str::uuid();
        $position = $this->nextPosition($menuId, $payload['parent_id'] ?? null);
        $target = !empty($payload['new_window']) ? '_blank' : '_self';

        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare(
                "INSERT INTO {$this->dfdb->prefix}navigation_item (item_id, menu_id, parent_id, item_type, object_id, object_type, item_custom_label, item_custom_url, item_target, item_css_class, item_position, item_attribute)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        $stmt->execute([
            $id,
            $menuId,
            $payload['parent_id'] ?? null,
            $payload['type'],
            $payload['object_id'] ?? null,
            $payload['object_type'] ?? null,
            $payload['custom_label'] ?? null,
            $payload['custom_url'] ?? null,
            $target,
            $payload['css_class'] ?? null,
            $position,
            json_encode($payload['attribute'] ?? [], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }

    public function updateItem(string $itemId, array $payload): void
    {
        $target = !empty($payload['new_window']) ? '_blank' : '_self';
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("UPDATE {$this->dfdb->prefix}navigation_item SET item_custom_label = ?, item_custom_url = ?, item_target = ?, item_css_class = ?, item_attribute = ? WHERE item_id = ?");
        $stmt->execute([$payload['custom_label'] ?? null, $payload['custom_url'] ?? null, $target, $payload['css_class'] ?? null, $payload['attribute'] ?? null, $itemId]);
    }

    public function removeItem(string $itemId): void
    {
        $stmt = $this->dfdb->getConnection()->pdo->prepare("DELETE FROM {$this->dfdb->prefix}navigation_item WHERE item_id = ?");
        $stmt->execute([$itemId]);
    }

    /**
     * @param list<array{id:string,parent_id:string|null,position:int}> $items
     * @throws Throwable
     */
    public function reorder(string $menuId, array $items): void
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("UPDATE {$this->dfdb->prefix}navigation_item SET parent_id = ?, item_position = ? WHERE item_id = ? AND menu_id = ?");
        $this->dfdb->getConnection()->pdo->beginTransaction();
        try {
            foreach ($items as $item) {
                $parentId = ($item['parent_id'] ?? null) ?: null;
                if ($parentId === $item['id']) {
                    $parentId = null;
                }
                $stmt->execute([$parentId, (int) $item['position'], $item['id'], $menuId]);
            }
            $this->dfdb->getConnection()->pdo->commit();
        } catch (\Throwable $e) {
            $this->dfdb->getConnection()->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string,list<array{id:string,label:string,url:string,type:string,object_type:string|null}>>
     * @throws TypeException
     */
    public function availableItems(): array
    {
        $groups = [];

        $groups['Pages'] = $this->fetchPages();
        $groups['Products'] = $this->fetchProducts();

        foreach ($this->fetchContentTypes() as $type) {
            $groups[$type['title']] = $this->fetchContentByType($type['slug']);
        }

        return $groups;
    }

    private function fetchPages(): array
    {
        $sql = "SELECT p.id, COALESCE(t.title, p.name) AS label, COALESCE(t.route, CONCAT('/', p.name)) AS url
                FROM {$this->dfdb->prefix}pages p
                LEFT JOIN {$this->dfdb->prefix}page_translations t ON t.page_id = p.id
                ORDER BY p.nav_position ASC, label ASC";
        return array_map(
            fn ($r) => ['id' => (string) $r['id'], 'label' => $r['label'], 'url' => $r['url'], 'type' => 'page', 'object_type' => null],
            $this->dfdb
                ->getConnection()
                ->pdo
                ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function fetchProducts(): array
    {
        $sql = "SELECT product_id AS id, product_title AS label, CONCAT('/product/', product_slug) AS url FROM {$this->dfdb->prefix}product WHERE product_status = 'published' ORDER BY product_title ASC";
        return array_map(
            fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'url' => $r['url'], 'type' => 'product', 'object_type' => null],
            $this->dfdb
                ->getConnection()
                ->pdo
                ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function fetchContentTypes(): array
    {
        $sql = "SELECT content_type_title AS title, content_type_slug AS slug FROM {$this->dfdb->prefix}content_type ORDER BY content_type_title ASC";
        return $this->dfdb
            ->getConnection()
            ->pdo
            ->query($sql)
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @throws TypeException
     */
    private function fetchContentByType(string $type): array
    {
        $sql = "SELECT content_id AS id, content_title AS label, CONCAT('/', content_type, '/', content_slug) AS url, content_type FROM {$this->dfdb->prefix}content WHERE content_type = ? AND content_status = 'published' ORDER BY content_title ASC";
        if (empty(config()->string(key: 'cms.relative_url'))) {
            $sql = "SELECT content_id AS id, content_title AS label, content_slug AS url, content_type FROM {$this->dfdb->prefix}content WHERE content_type = ? AND content_status = 'published' ORDER BY content_title ASC";
        }
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare($sql);
        $stmt->execute([$type]);
        return array_map(
            fn ($r) => ['id' => $r['id'], 'label' => $r['label'], 'url' => $r['url'], 'type' => 'content', 'object_type' => $r['content_type']],
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    private function uniqueMenuSlug(string $name, ?string $ignoreMenuId = null): string
    {
        $base = new Slugify()->slugify($name); // or your Devflow slug helper
        $slug = $base;
        $i = 2;

        while ($this->menuSlugExists($slug, $ignoreMenuId)) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function menuSlugExists(string $slug, ?string $ignoreMenuId = null): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->dfdb->prefix}navigation_menu WHERE menu_slug = ?";

        $params = [$slug];

        if ($ignoreMenuId !== null) {
            $sql .= " AND menu_id != ?";
            $params[] = $ignoreMenuId;
        }

        return (int) $this->dfdb->getVar($this->dfdb->prepare($sql, $params)) > 0;
    }

    private function nextPosition(string $menuId, ?string $parentId = null): int
    {
        $stmt = $this->dfdb
            ->getConnection()
            ->pdo
            ->prepare("SELECT COALESCE(MAX(item_position), -1) + 1 FROM {$this->dfdb->prefix}navigation_item WHERE menu_id = ? AND parent_id <> ?");
        $stmt->execute([$menuId, $parentId]);
        return (int) $stmt->fetchColumn();
    }
}
