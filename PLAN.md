# Implemented scope

- PHP 8.5 standalone Composer library, Survos namespace, no kernel dependency.
- Pinned upstream schema metadata and MIT attribution; reproducible import with provenance.
- Schema inheritance (including multiple inheritance), reverse properties, custom schemas.
- Entities and relations with multivalued string properties, typed property hooks, JSON interchange.
- Normalization and validation, partial dates, reference range validation and explicit errors.
- Python-generated compatibility fixtures and PHPUnit tests; document exact compatibility boundaries.
- Preserve OCR evidence separately, including source version, exact spans, confidence.
- Separate Elasticsearch mapping/document projection, rebuildable from authoritative entities.
- Usage examples and documentation, monorepo test integration; bundle only if needed.

Python matching, extraction, fuzzy normalization, database migrations, deployment and a review UI are outside this library.

Verification: the standalone and monorepo PHPUnit suites, PHPStan level 5, Composer validation, and an isolated Elasticsearch 9.5.3 integration run. Cleaning compatibility boundaries are documented in README.md; this is not a port of every Python type dependency.
