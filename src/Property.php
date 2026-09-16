<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney;

final class Property
{
    public string $name { get => $this->definition['name']; }
    public string $type { get => $this->definition['type'] ?? 'string'; }
    public ?string $range { get => $this->definition['range'] ?? null; }
    public ?string $format { get => $this->definition['format'] ?? null; }
    public bool $stub { get => $this->definition['stub'] ?? false; }

    /** @param array<string, mixed> $definition */
    public function __construct(public readonly array $definition)
    {
    }
}
