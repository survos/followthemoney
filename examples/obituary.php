<?php

declare(strict_types=1);

use Survos\FollowTheMoney\Model;
use Survos\FollowTheMoney\TextEvidence;

require is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../vendor/autoload.php';

$model = Model::bundled();
$emily = $model->create('Person', 'rappnews-emily-berry');
$emily->name = 'Emily Armstead Berry';
$emily->add('alias', 'Emily A. Berry');
$lucy = $model->create('Person', 'rappnews-lucy-berry');
$lucy->name = 'Lucy Barr Berry';
$family = $model->create('Family', 'rappnews-emily-lucy');
$family->add('person', $emily);
$family->add('relative', $lucy);
$family->add('relationship', 'mother'); // relative is the mother of person

$source = 'Funeral services for Miss Emily Armstead Berry';
$evidence = new TextEvidence(
    id: 'urn:annotation:rappnews-emily-1',
    entityId: $emily->id,
    source: 'https://example.org/articles/obituary-1', // replace with actual article URI
    text: $source,
    start: 26,
    end: mb_strlen($source),
    extractor: 'manual-example-v1',
);
foreach ([$emily, $lucy, $family] as $entity) {
    echo json_encode($entity, JSON_THROW_ON_ERROR)."\n";
}
// Evidence is stored beside the FtM graph, not as an invented Person property.
fwrite(STDERR, json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
