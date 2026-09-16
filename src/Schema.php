<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney;

use Survos\FollowTheMoney\Exception\InvalidData;

final class Schema
{
    /** @var array<string, Property> */
    public readonly array $properties;
    public string $label { get => $this->definition['label'] ?? $this->name; }
    public bool $abstract { get => $this->definition['abstract'] ?? false; }

    /** @param array<string, mixed> $definition */
    public function __construct(public readonly string $name, public readonly array $definition)
    {
        $properties = [];
        foreach ($definition['properties'] ?? [] as $key => $property) {
            $properties[$key] = new Property(['name' => $key, ...$property]);
        }
        $this->properties = $properties;
    }

    public function isA(string $name): bool
    {
        return $name === $this->name || in_array($name, $this->definition['schemata'] ?? [], true);
    }

    public function property(string $name): Property
    {
        return $this->properties[$name] ?? throw new InvalidData("Unknown property {$this->name}.{$name}");
    }
}
