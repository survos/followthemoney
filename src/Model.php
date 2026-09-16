<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney;

use Survos\FollowTheMoney\Exception\InvalidData;
use Survos\FollowTheMoney\Type\Normalizer;

final class Model
{
    /** @var array<string, Schema> */
    private array $schemas = [];
    public readonly Normalizer $normalizer;

    /** @param array<string, mixed> $snapshot */
    public function __construct(public readonly array $snapshot)
    {
        foreach ($snapshot['schemata'] as $name => $definition) {
            $this->schemas[$name] = new Schema($name, $definition);
        }
        $this->normalizer = new Normalizer($snapshot['types']);
    }

    public static function bundled(): self
    {
        return self::fromFile(__DIR__.'/../resources/model.json');
    }

    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new InvalidData("Cannot read model: {$path}");
        }
        return new self(json_decode($data, true, flags: JSON_THROW_ON_ERROR));
    }

    public function schema(string $name): Schema
    {
        return $this->schemas[$name] ?? throw new InvalidData("Unknown schema: {$name}");
    }

    /** @return array<string, Schema> */
    public function schemas(): array
    {
        return $this->schemas;
    }

    /**
     * Add local schema definitions without modifying the pinned upstream model.
     * Definitions use FtM names, extends, properties and reverse metadata.
     * @param array<string, array<string, mixed>> $definitions
     */
    public function withSchemas(array $definitions): self
    {
        $data = $this->snapshot;
        $visiting = [];
        foreach ($definitions as $name => $definition) {
            if (isset($data['schemata'][$name])) {
                throw new InvalidData("Cannot replace existing schema: {$name}");
            }
        }
        $resolve = function (string $name) use (&$resolve, &$data, &$visiting, $definitions): array {
            if (isset($data['schemata'][$name])) {
                return $data['schemata'][$name];
            }
            if (isset($visiting[$name]) || !isset($definitions[$name])) {
                throw new InvalidData("Cyclic or unknown parent schema: {$name}");
            }
            $visiting[$name] = true;
            $definition = $definitions[$name];
            $properties = [];
            $ancestors = [$name];
            $inherited = [];
            foreach ($definition['extends'] ?? [] as $parent) {
                $base = $resolve($parent);
                $properties = array_replace($properties, $base['properties']);
                $ancestors = array_merge($ancestors, $base['schemata']);
                foreach (['required', 'caption', 'featured'] as $key) {
                    $inherited[$key] = array_values(array_unique(array_merge($inherited[$key] ?? [], $base[$key] ?? [])));
                }
            }
            foreach ($definition['properties'] ?? [] as $key => $property) {
                $type = $property['type'] ?? 'string';
                if (!isset($data['types'][$type])) {
                    throw new InvalidData("Unknown property type: {$type}");
                }
                $properties[$key] = ['name' => $key, 'type' => $type, 'maxLength' => $data['types'][$type]['maxLength'], ...$property];
            }
            $result = array_replace($inherited, $definition, ['properties' => $properties, 'schemata' => array_values(array_unique($ancestors))]);
            unset($visiting[$name]);
            return $data['schemata'][$name] = $result;
        };
        foreach (array_keys($definitions) as $name) {
            $resolve($name);
        }
        foreach ($definitions as $name => $definition) {
            foreach ($definition['properties'] ?? [] as $key => $property) {
                if (($property['type'] ?? null) !== 'entity') {
                    continue;
                }
                $range = $property['range'] ?? 'Thing';
                if (!isset($data['schemata'][$range])) {
                    throw new InvalidData("Unknown entity range: {$range}");
                }
                $data['schemata'][$name]['properties'][$key]['range'] = $range;
                if (!isset($property['reverse'])) {
                    continue;
                }
                $reverse = $property['reverse'];
                if (!is_array($reverse) || !is_string($reverse['name'] ?? null)) {
                    throw new InvalidData('Reverse property requires a name');
                }
                foreach ($data['schemata'] as $target => &$targetDefinition) {
                    if (!in_array($range, $targetDefinition['schemata'], true)) {
                        continue;
                    }
                    if (isset($targetDefinition['properties'][$reverse['name']])) {
                        throw new InvalidData("Reverse property conflict: {$target}.{$reverse['name']}");
                    }
                    $targetDefinition['properties'][$reverse['name']] = [
                        ...$reverse, 'type' => 'entity', 'range' => $name, 'stub' => true, 'reverse' => $key,
                    ];
                }
                unset($targetDefinition);
            }
        }
        return new self($data);
    }

    public function create(string $schema, string $id): Entity
    {
        return new Entity($this, $this->schema($schema), $id);
    }

    /** @param array<string, mixed> $data */
    public function fromArray(array $data, bool $normalized = true): Entity
    {
        if (!is_string($data['schema'] ?? null) || !is_string($data['id'] ?? null) || !is_array($data['properties'] ?? null)) {
            throw new InvalidData('Expected id, schema and properties in an FtM entity');
        }
        $entity = $this->create($data['schema'], $data['id']);
        $entity->metadata = array_diff_key($data, array_flip(['id', 'schema', 'properties']));
        foreach ($data['properties'] as $name => $values) {
            if (!is_array($values) || !array_is_list($values)) {
                throw new InvalidData("Property {$name} must be an array of values");
            }
            $entity->add($name, $values, $normalized);
        }
        return $entity;
    }
}
