<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney;

use Survos\FollowTheMoney\Exception\InvalidData;

final class Entity implements \JsonSerializable
{
    /** @var array<string, list<string>> */
    private array $values = [];

    /** @var array<string, mixed> Optional interchange metadata (datasets, caption, etc.). */
    public array $metadata = [];

    /** @var list<string> */
    public array $names {
        get => $this->get('name');
        set {
            $this->set('name', $value);
        }
    }
    public ?string $name {
        get => $this->get('name')[0] ?? null;
        set {
            $this->set('name', $value);
        }
    }

    public function __construct(
        private readonly Model $model,
        public readonly Schema $schema,
        public readonly string $id,
    ) {
        if (!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $id)) {
            throw new InvalidData("Invalid entity ID: {$id}");
        }
    }

    /** @return list<string> */
    public function get(string $name): array
    {
        $this->schema->property($name);
        return $this->values[$name] ?? [];
    }

    public function add(string $name, mixed $values, bool $normalized = false): void
    {
        $property = $this->schema->property($name);
        if ($property->stub) {
            throw new InvalidData("Cannot write reverse property: {$name}");
        }
        $pending = $this->values[$name] ?? [];
        foreach (is_array($values) && array_is_list($values) ? $values : [$values] as $value) {
            if ($value instanceof self) {
                if ($property->type !== 'entity' || ($property->range !== null && !$value->schema->isA($property->range))) {
                    throw new InvalidData("Invalid entity range for {$name}");
                }
                $value = $value->id;
            }
            if ($normalized && !is_string($value)) {
                throw new InvalidData("Normalized {$name} values must be strings");
            }
            $clean = $normalized ? $value : $this->model->normalizer->clean($property, $value);
            if ($clean === null) {
                continue;
            }
            if ($property->type === 'entity' && $clean === $this->id) {
                throw new InvalidData("Self relationship: {$name}");
            }
            if (!in_array($clean, $pending, true)) {
                $pending[] = $clean;
            }
        }
        if ($pending !== []) {
            $this->values[$name] = $pending;
        }
    }

    public function set(string $name, mixed $values, bool $normalized = false): void
    {
        $copy = clone $this;
        unset($copy->values[$name]);
        $copy->add($name, $values, $normalized);
        $this->values = $copy->values;
    }

    /** @return list<string> Validation is explicit so extraction fragments can be incomplete. */
    public function validate(?callable $resolve = null): array
    {
        $errors = [];
        foreach ($this->schema->definition['required'] ?? [] as $name) {
            if ($this->get($name) === []) {
                $errors[] = "Missing required property: {$name}";
            }
        }
        foreach ($this->values as $name => $values) {
            $property = $this->schema->property($name);
            foreach ($values as $value) {
                if (mb_strlen($value) > ($property->definition['maxLength'] ?? PHP_INT_MAX)) {
                    $errors[] = "Value too long: {$name}";
                }
                if ($property->type === 'entity' && !preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $value)) {
                    $errors[] = "Invalid reference ID: {$name}";
                }
            }
        }
        if ($resolve !== null) {
            foreach ($this->values as $name => $values) {
                $property = $this->schema->property($name);
                if ($property->type !== 'entity') {
                    continue;
                }
                foreach ($values as $id) {
                    $target = $resolve($id);
                    if (!$target instanceof self || ($property->range !== null && !$target->schema->isA($property->range))) {
                        $errors[] = "Unresolved or incompatible reference: {$name} -> {$id}";
                    }
                }
            }
        }
        return $errors;
    }

    public function jsonSerialize(): array
    {
        return [...$this->metadata, 'id' => $this->id, 'schema' => $this->schema->name, 'properties' => (object) $this->values];
    }
}
