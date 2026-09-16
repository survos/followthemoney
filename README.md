# survos/followthemoney

Based on [FollowTheMoney — original project and documentation](https://followthemoney.tech/)
and the [original Python library, alephdata/followthemoney](https://github.com/alephdata/followthemoney).
Browse the upstream [schema explorer](https://followthemoney.tech/explorer/) for the model
we reuse. This PHP package is maintained independently by Survos.

PHP 8.5 library for the FollowTheMoney entity model, with no Python process,
Symfony kernel, database or search service required at runtime. MIT licensed.

The package ships all 70 schemas from upstream revision
`c70a89ffa59ea785956f6095643310da3735b7fb`, including resolved inheritance,
reverse properties, relationship edges and display metadata. The snapshot and
its SHA-256 are in `resources/`. This is an independent PHP implementation,
not an official FollowTheMoney release or a complete port of its Python tools.

## Installation

Install the [published package on Packagist](https://packagist.org/packages/survos/followthemoney):

```sh
composer require survos/followthemoney
```

Requires PHP 8.5, ext-intl and ext-mbstring. No Symfony or Python runtime is required.
For Symfony applications that process JSONL files, also install `survos/jsonl-bundle`.

For development inside the Survos monorepo, the mono root already registers the
library's namespace and includes its tests. Other local applications may use a
Composer path repository pointing to `mono/lib/followthemoney`.

## Entities and relationships

```php
use Survos\FollowTheMoney\Model;

$model = Model::bundled();
$emily = $model->create('Person', 'rappnews-emily-berry');
$emily->name = 'Emily Armstead Berry'; // virtual property hook
$emily->add('alias', 'Emily A. Berry');
$emily->add('deathDate', '1953'); // precision is retained
$emily->add('country', 'United States'); // us

$lucy = $model->create('Person', 'rappnews-lucy-berry');
$lucy->name = 'Lucy Barr Berry';
$family = $model->create('Family', 'rappnews-emily-lucy');
$family->add('person', $emily);
$family->add('relative', $lucy);
$family->add('relationship', 'mother'); // Lucy is Emily's mother

echo json_encode($emily, JSON_THROW_ON_ERROR);
```

All properties are ordered, deduplicated lists of strings, including dates,
numbers and references. `name` reads the first value and replaces the `name` list on assignment; `names` accesses/replaces the entire `name` property. Neither parses a
personal name into given/family names. Use `get()`, `add()` and `set()` for every
schema property. Updates are atomic when a cleaner throws. Schema and property
metadata are immutable; the model can be loaded once and reused.

Identifiers are supplied by the application. Do not use a person's name alone
as an identity key. This library does not infer that similar names identify the
same person, or infer gender from names.

## Import, normalization and validation

`$model->fromArray($wire)` imports **already normalized** FtM wire data, preserving
string values and optional top-level metadata. Unknown properties and writes to
reverse stubs fail. Use the existing `survos/jsonl-bundle` for streaming files, gzip and sidecars:

```php
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;

$writer = JsonlWriter::open('/path/to/output.jsonl');
try {
    foreach (JsonlReader::open('/path/to/input.jsonl') as $row) {
        $entity = $model->fromArray($row);
        $writer->write($entity); // Entity implements JsonSerializable
    }
    $writer->finish();
} finally {
    $writer->close();
}
```

The bundle is an optional application dependency. It owns file IO; this library
owns the FtM model and row conversion. There is no duplicate JSONL reader/writer.

For raw input use `add()`, `set()` or `fromArray($wire, normalized: false)`.
As in Python, a cleaner returning null drops an invalid/empty input; unknown
properties, unsupported cleaners and self-references throw. For applications
that must report rejected input, call `normalizer->clean($property, $raw)` first
and retain the original input and rejection separately. Normalized imports do
not silently re-clean historical or externally supplied values.

`validate()` reports missing required properties, excessive lengths and malformed
reference IDs. Pass an ID resolver to check dangling references and schema ranges:

```php
$errors = $family->validate(fn (string $id) => $entitiesById[$id] ?? null);
```

It is a structural validator, not a claim that arbitrary imported strings have
passed every upstream type validator. Passing Entity objects to `add()` checks
the target schema immediately. ID-only references are checked with the resolver.
Incomplete extraction fragments can exist before validation.

### Cleaning compatibility

Python-generated fixture tests cover the implemented cleaning profile:

- Unicode NFC and unsafe-character removal; strings, text, HTML, numbers and checksums.
- Names and addresses, without OCR spelling correction or title inference.
- Prefix dates, including partial precision, following prefixdate 0.6.1.
- Entity IDs, gender aliases, email addresses and Wikidata Q-identifiers.
- Country/language codes and English labels from the pinned upstream/Babel tables;
  topic codes.
- Basic HTTP/HTTPS/FTP URLs, including a default HTTP scheme and empty path.

This is not universal normalization parity. Arbitrary multilingual country names,
fuzzy matching, advanced URL repairs, phone/IP/IBAN/MIME/JSON cleaning, non-QID
identifier formats and custom date formats are not implemented. Already-normalized
values of these types can be imported and exported without loss. Raw unsupported
types/formats throw rather than being treated as plain text. Register a cleaner
with `normalizer->register($type, callable)` when an application needs one.
A cleaner receives the raw value and Property, and returns a string or null.

Raw HTML remains HTML. Escape values in views; this library does not sanitize HTML
for browser rendering. The `properties` wire object and returned arrays are copies.

## Custom schemas

```php
$model = $model->withSchemas([
    'HistoricalPerson' => [
        'extends' => ['Person'],
        'label' => 'Historical person',
        'properties' => ['localKey' => ['type' => 'identifier']],
    ],
]);
```

Custom schemas support multiple parents, inherited metadata and entity ranges.
An entity property may declare `reverse: ['name' => 'mentions', 'label' => 'Mentions']`;
the corresponding read-only stub is generated on its range and descendants.
Cycles, unknown parents/types/ranges, replacement of upstream schemas and reverse
name collisions fail. Extensions use native PHP arrays; Symfony YAML can supply
the same definitions without adding a YAML dependency to this library.

## OCR evidence

`TextEvidence` stores a W3C Web Annotation with TextPositionSelector and
TextQuoteSelector, plus a separate provenance envelope carrying the exact source
SHA-256, extractor version and optional confidence. Offsets are Unicode code
points, end exclusive. Keep the original OCR text unchanged and retrieve the
matching source version before applying offsets. ALTO boxes/page anchors can
remain in the existing article store alongside this evidence.

Run `php lib/followthemoney/examples/obituary.php` from mono for the Emily/Lucy
example. It writes FtM JSONL to stdout and evidence to stderr. Its source URI is a
placeholder; no unverified death date or Wikidata identity is asserted.

## Elasticsearch

`Search\ElasticsearchProjection` supplies a mapping, documents and streaming Bulk
API NDJSON. It has no transport, network calls or service-container dependencies:

```php
$projection = new Survos\FollowTheMoney\Search\ElasticsearchProjection();
$createIndexBody = ['mappings' => $projection->mapping()];
$bulkLines = $projection->bulk($entities, 'newspaper-entities-v1');
```

`names` and `text` support full-text search; `schema`, `schemata`, `references` and
`names.raw` support filters. The original `properties` object uses Elasticsearch
`flattened`: e.g. a term query on `properties.deathDate` finds `1953`. Partial dates
remain strings; range queries on this field are lexical, not chronological.
Long property values remain in `_source` but values over 8191 characters are not
keyword-indexed, avoiding Lucene term-size errors. Full-text fields still contain
eligible text. Optional wire metadata is not copied into the strict search mapping.

The adapter is an independent projection, not OpenAleph's internal index format.
Reindex from the authoritative entity store when mappings change. PostgreSQL,
Doctrine, Messenger, permissions, review screens, poi-bundle lookups and MCP belong
to consuming applications or a later Symfony bundle. No bundle is needed to use
this library through normal Symfony service registration.

The integration test was run against Elasticsearch 9.5.3, including bulk indexing,
full-text name search, exact partial-date queries, inherited-schema filters,
relationship references and a long text value. Run it against your deployment with
`FTM_ELASTICSEARCH_URL=http://127.0.0.1:9200 vendor/bin/phpunit -c lib/followthemoney/phpunit.xml.dist`
(from mono). It creates and removes a uniquely named `ftm-test-*` index and is
skipped when that environment variable is absent.

## Tests and upstream refresh

From mono:

```sh
vendor/bin/phpunit -c lib/followthemoney/phpunit.xml.dist
vendor/bin/phpunit
```

Standalone: `composer install && composer test`. Python is used only to regenerate
schemas and expectations, never to execute PHPUnit or serve entity requests.

To reproduce the shipped resources, create an isolated Python environment, install
`tools/requirements.lock`, clone upstream at the revision in `resources/upstream.json`,
then run:

```sh
python tools/export_upstream.py /path/to/pinned/followthemoney
python tools/generate_fixtures.py
```

Run these from the library directory using that environment. PyICU may require ICU
headers/pkg-config (on Homebrew set `PKG_CONFIG_PATH` to ICU's `lib/pkgconfig`).
The Python package in the environment must match the checkout. Review schema,
fixture and lockfile differences together on upgrades. Tests verify the snapshot
hash and all reference ranges in addition to behavior. Fixture expectations come
from Python; they are not produced by the PHP cleaner.

## Attribution and references

Upstream model and portions of cleaning behavior: [FollowTheMoney](https://github.com/alephdata/followthemoney),
MIT; license retained in `resources/UPSTREAM-LICENSE`. Prefix parsing:
[prefixdate](https://github.com/pudo/prefixdate), MIT; retained in
`resources/PREFIXDATE-LICENSE`. No OpenAleph AGPL implementation was copied.

- [FollowTheMoney schema explorer](https://followthemoney.tech/explorer/)
- [W3C Web Annotation model](https://www.w3.org/TR/annotation-model/)
- [Elasticsearch flattened fields](https://www.elastic.co/docs/reference/elasticsearch/mapping-reference/flattened)
