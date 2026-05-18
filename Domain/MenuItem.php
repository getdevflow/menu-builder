<?php

declare(strict_types=1);

namespace Plugin\MenuBuilder\Domain;

use JsonException;
use Qubus\Exception\Data\TypeException;

use function Codefy\Framework\Helpers\config;
use function ltrim;
use function trim;

final class MenuItem
{
    /**
     * @param MenuItem[] $children
     */
    public function __construct(
        private readonly string $id,
        private readonly string $menuId,
        private readonly string $type,
        private readonly ?string $parentId = null,
        private readonly ?string $objectId = null,
        private readonly ?string $objectType = null,
        private readonly ?string $customLabel = null,
        private readonly ?string $customUrl = null,
        private readonly ?string $resolvedTitle = null,
        private readonly ?string $resolvedSlug = null,
        private readonly string $target = '_self',
        private readonly ?string $cssClass = null,
        private readonly int $position = 0,
        private readonly ?string $attribute = null,
        private array $children = []
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function menuId(): string
    {
        return $this->menuId;
    }

    public function parentId(): ?string
    {
        return $this->parentId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function objectId(): ?string
    {
        return $this->objectId;
    }

    public function objectType(): ?string
    {
        return $this->objectType;
    }

    public function label(): string
    {
        return $this->customLabel
                ?: $this->resolvedTitle
                        ?: 'Untitled';
    }

    /**
     * @throws TypeException
     */
    public function url(): string
    {
        if ($this->isCustom()) {
            return (string) $this->customUrl;
        }

        $content = match ($this->type === 'content') {
            empty(config()->string(key: 'cms.relative_url')) => '/' . ltrim($this->resolvedSlug, '/'),
            default => '/' . trim((string) $this->objectType, '/') . '/' . ltrim($this->resolvedSlug, '/'),
        };

        return match ($this->type) {
            'page' => '/' . ltrim($this->resolvedSlug, '/'),
            'product' => '/product/' . ltrim($this->resolvedSlug, '/'),
            'content' => $content,
            default => '#',
        };
    }

    public function target(): string
    {
        return $this->target;
    }

    public function cssClass(): string
    {
        return trim((string) $this->cssClass);
    }

    public function position(): int
    {
        return $this->position;
    }

    public function rawAttribute(): ?string
    {
        return $this->attribute;
    }

    public function isRoot(): bool
    {
        return $this->parentId === null || $this->parentId === '';
    }

    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * @return MenuItem[]
     */
    public function children(): array
    {
        return $this->children;
    }

    public function addChild(MenuItem $child): void
    {
        $this->children[] = $child;
    }

    /**
     * @param MenuItem[] $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            id: $this->id,
            menuId: $this->menuId,
            type: $this->type,
            parentId: $this->parentId,
            objectId: $this->objectId,
            objectType: $this->objectType,
            customLabel: $this->customLabel,
            customUrl: $this->customUrl,
            resolvedTitle: $this->resolvedTitle,
            resolvedSlug: $this->resolvedSlug,
            target: $this->target,
            cssClass: $this->cssClass,
            position: $this->position,
            attribute: $this->attribute,
            children: $children
        );
    }

    public function opensInNewWindow(): bool
    {
        return $this->target === '_blank';
    }

    public function isCustom(): bool
    {
        return $this->type === 'custom';
    }

    public function isPage(): bool
    {
        return $this->type === 'page';
    }

    public function isProduct(): bool
    {
        return $this->type === 'product';
    }

    public function isContent(): bool
    {
        return $this->type === 'content';
    }

    public function attributes(): array
    {
        $attributes = $this->decodeAttributes();

        if ($this->cssClass() !== '') {
            $attributes['class'] = trim(($attributes['class'] ?? '') . ' ' . $this->cssClass());
        }

        if ($this->opensInNewWindow()) {
            $attributes['target'] = '_blank';

            $rel = trim((string) ($attributes['rel'] ?? ''));
            $parts = $rel === '' ? [] : preg_split('/\s+/', $rel);

            $parts[] = 'noopener';
            $parts[] = 'noreferrer';

            $attributes['rel'] = implode(' ', array_values(array_unique(array_filter($parts))));
        }

        return array_filter(
            $attributes,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    public function rawAttributes(): array
    {
        $attributes = $this->decodeAttributes();

        unset($attributes['class']);

        return $attributes;
    }

    private function decodeAttributes(): array
    {
        if ($this->attribute === null || trim($this->attribute) === '') {
            return [];
        }

        try {
            $decoded = json_decode($this->attribute, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public static function fromArray(array $data, array $children = []): self
    {
        return new self(
            id: (string) ($data['item_id'] ?? $data['id'] ?? ''),
            menuId: (string) ($data['menu_id'] ?? ''),
            type: (string) ($data['item_type'] ?? $data['type'] ?? ''),
            parentId: isset($data['parent_id']) && $data['parent_id'] !== ''
                    ? (string) $data['parent_id']
                    : null,
            objectId: isset($data['object_id'])
                    ? (string) $data['object_id']
                    : null,
            objectType: isset($data['object_type'])
                    ? (string) $data['object_type']
                    : null,
            customLabel: isset($data['item_custom_label'])
                    ? (string) $data['item_custom_label']
                    : null,
            customUrl: isset($data['item_custom_url'])
                    ? (string) $data['item_custom_url']
                    : null,
            resolvedTitle: isset($data['resolved_title'])
                    ? (string) $data['resolved_title']
                    : null,
            resolvedSlug: isset($data['resolved_slug'])
                    ? (string) $data['resolved_slug']
                    : null,
            target: (string) ($data['item_target'] ?? $data['target'] ?? '_self'),
            cssClass: isset($data['item_css_class'])
                    ? (string) $data['item_css_class']
                    : null,
            position: (int) ($data['item_position'] ?? $data['position'] ?? 0),
            attribute: isset($data['item_attribute'])
                    ? (string) $data['item_attribute']
                    : null,
            children: $children
        );
    }
}
