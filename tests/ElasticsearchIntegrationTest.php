<?php

declare(strict_types=1);

namespace Survos\FollowTheMoney\Tests;

use PHPUnit\Framework\TestCase;
use Survos\FollowTheMoney\Model;
use Survos\FollowTheMoney\Search\ElasticsearchProjection;

final class ElasticsearchIntegrationTest extends TestCase
{
    public function testIndexAndQueries(): void
    {
        $url = getenv('FTM_ELASTICSEARCH_URL');
        if (!$url) {
            self::markTestSkipped('Set FTM_ELASTICSEARCH_URL for a disposable Elasticsearch test index.');
        }
        $index = 'ftm-test-'.bin2hex(random_bytes(8));
        $request = static function (string $method, string $path, ?string $body = null, string $contentType = 'application/json') use ($url): array {
            $context = stream_context_create(['http' => [
                'method' => $method,
                'header' => "Content-Type: {$contentType}\r\n",
                'content' => $body ?? '',
                'ignore_errors' => true,
                'timeout' => 30,
            ]]);
            $response = file_get_contents(rtrim($url, '/').'/'.$path, false, $context);
            self::assertNotFalse($response);
            $data = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('error', $data, $response);
            return $data;
        };
        $projection = new ElasticsearchProjection();
        $request('PUT', $index, json_encode(['mappings' => $projection->mapping()], JSON_THROW_ON_ERROR));
        try {
            $model = Model::bundled();
            $person = $model->create('Person', 'emily');
            $person->name = 'Emily Armstead Berry';
            $person->add('deathDate', '1953');
            $person->add('notes', str_repeat('long ', 9000));
            $person->metadata = ['datasets' => ['rappnews']];
            $family = $model->create('Family', 'family-1');
            $family->add('person', $person);
            $family->add('relative', 'lucy');
            $family->add('relationship', 'mother');
            $body = implode('', iterator_to_array($projection->bulk([$person, $family], $index)));
            $result = $request('POST', '_bulk?refresh=true', $body, 'application/x-ndjson');
            self::assertFalse($result['errors'], json_encode($result));
            foreach ([
                ['match' => ['names' => 'Emily']],
                ['term' => ['properties.deathDate' => '1953']],
                ['term' => ['schemata' => 'Person']],
            ] as $query) {
                $hits = $request('POST', $index.'/_search', json_encode(['query' => $query], JSON_THROW_ON_ERROR));
                self::assertSame('emily', $hits['hits']['hits'][0]['_id']);
                self::assertSame(['1953'], $hits['hits']['hits'][0]['_source']['properties']['deathDate']);
            }
            $hits = $request('POST', $index.'/_search', json_encode(['query' => ['term' => ['references' => 'emily']]], JSON_THROW_ON_ERROR));
            self::assertSame('family-1', $hits['hits']['hits'][0]['_id']);
        } finally {
            $request('DELETE', $index);
        }
    }
}
