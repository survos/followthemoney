<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Survos\FollowTheMoney\Model;
use Survos\FollowTheMoney\Property;

final class CompatibilityTest extends TestCase
{
    public function testFixtureProvenance(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/python.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(json_decode(file_get_contents(__DIR__.'/../resources/upstream.json'), true), $fixtures['upstream']);
        self::assertSame(hash_file('sha256', __DIR__.'/../tools/requirements.lock'), $fixtures['requirements_sha256']);
    }

    public static function cases(): iterable
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/python.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($fixtures['normalization'] as $index => $case) {
            yield $case['type'].'-'.$index => [$case];
        }
    }

    #[DataProvider('cases')]
    public function testPythonNormalization(array $case): void
    {
        $property = new Property(['name' => 'test', 'type' => $case['type'], 'format' => $case['format']]);
        self::assertSame($case['expected'], Model::bundled()->normalizer->clean($property, $case['input']));
    }

    public function testPythonEntities(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/fixtures/python.json'), true, flags: JSON_THROW_ON_ERROR);
        $model = Model::bundled();
        foreach ($fixtures['entities'] as $case) {
            $entity = $model->create($case['schema'], $case['id']);
            foreach ($case['input'] as $name => $values) {
                $entity->add($name, $values);
            }
            self::assertEquals($case['expected'], json_decode(json_encode($entity, JSON_THROW_ON_ERROR), true));
        }
    }
}
