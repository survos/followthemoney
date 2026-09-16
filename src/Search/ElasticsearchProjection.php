<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Search;

use Survos\FollowTheMoney\Entity;

/** A rebuildable search projection, independent of Elasticsearch clients and storage. */
final class ElasticsearchProjection
{
    public function mapping(): array
    {
        return ['dynamic' => 'strict', 'properties' => [
            'id' => ['type' => 'keyword'],
            'schema' => ['type' => 'keyword'],
            'schemata' => ['type' => 'keyword'],
            'names' => ['type' => 'text', 'fields' => ['raw' => ['type' => 'keyword', 'ignore_above' => 1024]]],
            'text' => ['type' => 'text'],
            'references' => ['type' => 'keyword'],
            // Partial dates and all other FtM properties remain strings.
            // flattened also avoids one mapping field per schema/property.
            'properties' => ['type' => 'flattened', 'ignore_above' => 8191],
        ]];
    }

    public function document(Entity $entity): array
    {
        $wire = array_intersect_key($entity->jsonSerialize(), array_flip(['id', 'schema', 'properties']));
        $names = $references = $text = [];
        foreach ((array) $wire['properties'] as $name => $values) {
            $property = $entity->schema->property($name);
            if ($property->type === 'entity') {
                $references = array_merge($references, $values);
            }
            if ($property->type === 'name') {
                $names = array_merge($names, $values);
            }
            if (!$property->stub && !($property->definition['hidden'] ?? false) && in_array($property->type, ['name', 'string', 'text', 'address'], true)) {
                $text = array_merge($text, $values);
            }
        }
        return [
            ...$wire,
            'schemata' => $entity->schema->definition['schemata'],
            'names' => array_values(array_unique($names)),
            'references' => array_values(array_unique($references)),
            'text' => array_values(array_unique($text)),
        ];
    }

    /** @param iterable<Entity> $entities @return \Generator<string> */
    public function bulk(iterable $entities, string $index): \Generator
    {
        foreach ($entities as $entity) {
            yield json_encode(['index' => ['_index' => $index, '_id' => $entity->id]], JSON_THROW_ON_ERROR)."\n";
            yield json_encode($this->document($entity), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        }
    }
}
