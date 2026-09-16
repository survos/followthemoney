<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FollowTheMoney\Exception\InvalidData;
use Survos\FollowTheMoney\Model;
use Survos\FollowTheMoney\TextEvidence;
use Survos\FollowTheMoney\Search\ElasticsearchProjection;

final class ModelTest extends TestCase
{
    public function testSnapshotIntegrity(): void
    {
        $manifest = json_decode(file_get_contents(__DIR__.'/../resources/upstream.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($manifest['model_sha256'], hash_file('sha256', __DIR__.'/../resources/model.json'));
        $model = Model::bundled();
        foreach ($model->schemas() as $schema) {
            foreach ($schema->properties as $property) {
                if ($property->range !== null) {
                    self::assertSame($property->range, $model->schema($property->range)->name);
                }
            }
        }
    }

    public function testInheritanceAndReverseProperties(): void
    {
        $model = Model::bundled();
        self::assertCount(70, $model->schemas());
        $person = $model->schema('Person');
        self::assertTrue($person->isA('LegalEntity'));
        self::assertTrue($person->isA('Thing'));
        self::assertSame('name', $person->property('name')->type);
        self::assertTrue($person->property('familyPerson')->stub);
        self::assertSame('Person', $model->schema('Family')->property('relative')->range);
        self::assertSame('person', $model->schema('Family')->definition['edge']['source']);
    }

    public function testHooksAndAtomicUpdates(): void
    {
        $person = Model::bundled()->create('Person', 'emily');
        $person->names = ['Emily Berry', 'Emily Berry', 'Emily A. Berry'];
        self::assertSame(['Emily Berry', 'Emily A. Berry'], $person->names);
        $person->name = 'Emily Armstead Berry';
        self::assertSame(['Emily Armstead Berry'], $person->names);
        try {
            $person->set('name', ['good', new \stdClass()]);
            self::fail('Non-scalar should throw');
        } catch (InvalidData) {
            self::assertSame('Emily Armstead Berry', $person->name);
        }
    }

    public function testRelationsValidateRangeAndResolveIds(): void
    {
        $model = Model::bundled();
        $person = $model->create('Person', 'emily');
        $relative = $model->create('Person', 'lucy');
        $family = $model->create('Family', 'family-1');
        $family->add('person', $person);
        $family->add('relative', $relative);
        $family->add('relationship', 'mother');
        self::assertSame([], $family->validate(fn ($id) => ['emily' => $person, 'lucy' => $relative][$id] ?? null));
        self::assertCount(2, $family->validate(fn () => null));
        $this->expectException(InvalidData::class);
        $family->set('relative', $model->create('Organization', 'church'));
    }

    public function testReversePropertiesAreReadOnly(): void
    {
        $this->expectException(InvalidData::class);
        Model::bundled()->create('Person', 'emily')->add('familyPerson', 'family-1');
    }

    public function testUnknownPropertiesFail(): void
    {
        $this->expectException(InvalidData::class);
        Model::bundled()->create('Person', 'emily')->add('typo', 'value');
    }

    public function testSelfRelationshipsFail(): void
    {
        $this->expectException(InvalidData::class);
        Model::bundled()->create('Person', 'emily')->add('addressEntity', 'emily');
    }

    public function testCustomMultipleInheritanceAndReverse(): void
    {
        $model = Model::bundled()->withSchemas([
            'LocalPerson' => ['extends' => ['Person'], 'properties' => ['localKey' => ['type' => 'identifier']]],
            'Tagged' => ['properties' => ['tag' => []]],
            'NewspaperPerson' => ['extends' => ['LocalPerson', 'Tagged'], 'properties' => ['mentionedIn' => [
                'type' => 'entity', 'range' => 'Document', 'reverse' => ['name' => 'localMentions'],
            ]]],
        ]);
        $schema = $model->schema('NewspaperPerson');
        self::assertTrue($schema->isA('Person'));
        self::assertSame('string', $schema->property('tag')->type);
        self::assertSame('identifier', $schema->property('localKey')->type);
        self::assertTrue($model->schema('Document')->property('localMentions')->stub);
        self::assertSame('NewspaperPerson', $model->schema('Document')->property('localMentions')->range);
    }

    public function testCycleFails(): void
    {
        $this->expectException(InvalidData::class);
        Model::bundled()->withSchemas(['A' => ['extends' => ['B']], 'B' => ['extends' => ['A']]]);
    }

    public function testInterchangePreservesNormalizedUnsupportedTypes(): void
    {
        $model = Model::bundled();
        $wire = ['datasets' => ['rappnews'], 'id' => 'emily', 'schema' => 'Person', 'properties' => ['phone' => ['+12025550123'], 'name' => ['Emily']]];
        $entity = $model->fromArray($wire);
        self::assertSame($wire, json_decode(json_encode($entity, JSON_THROW_ON_ERROR), true));
        self::assertSame(['Missing required property: name'], $model->create('Person', 'empty')->validate());
    }

    public function testElasticsearchProjectionPreservesPartialDates(): void
    {
        $entity = Model::bundled()->create('Person', 'emily');
        $entity->name = 'Emily Berry';
        $entity->add('deathDate', '1953');
        $projection = new ElasticsearchProjection();
        $document = $projection->document($entity);
        self::assertSame(['1953'], ((array) $document['properties'])['deathDate']);
        self::assertSame(['Emily Berry'], $document['names']);
        self::assertSame('flattened', $projection->mapping()['properties']['properties']['type']);
        $bulk = iterator_to_array($projection->bulk([$entity], 'people'));
        self::assertSame(['index' => ['_index' => 'people', '_id' => 'emily']], json_decode($bulk[0], true));
        self::assertCount(2, $bulk);
    }

    public function testEvidenceUsesCodePointsAndSourceRevision(): void
    {
        $text = 'É: Emily Berry died.';
        $evidence = new TextEvidence('urn:annotation:1', 'emily', 'https://example.org/article/1', $text, 3, 14, 'extractor-v1', 0.8);
        self::assertSame('Emily Berry', $evidence->exact);
        self::assertSame(hash('sha256', $text), $evidence->sourceSha256);
        self::assertSame(3, $evidence->jsonSerialize()['annotation']['target']['selector'][0]['start']);
        $this->expectException(InvalidData::class);
        new TextEvidence('a', 'b', 'c', $text, 0, 999, 'v1');
    }
}
