<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Domain;

final class Menu
{
    /**
     * @param MenuItem[] $items
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly string $slug,
        private readonly ?string $description = null,
        private array $items = []
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return MenuItem[]
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * @return MenuItem[]
     */
    public function rootItems(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (MenuItem $item): bool => $item->isRoot()
        ));
    }

    public function addItem(MenuItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @param MenuItem[] $items
     */
    public function withItems(array $items): self
    {
        return new self(
            id: $this->id,
            name: $this->name,
            slug: $this->slug,
            description: $this->description,
            items: $items
        );
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public static function fromArray(array $data, array $items = []): self
    {
        return new self(
            id: (string) ($data['menu_id'] ?? $data['id'] ?? ''),
            name: (string) ($data['menu_name'] ?? $data['name'] ?? ''),
            slug: (string) ($data['menu_slug'] ?? $data['slug'] ?? ''),
            description: isset($data['menu_description'])
                ? (string) $data['menu_description']
                : null,
            items: $items
        );
    }
}
