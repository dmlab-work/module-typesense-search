# DmLab_TypesenseSearch

[![CI](https://github.com/dmlab-work/module-typesense-search/actions/workflows/ci.yml/badge.svg)](https://github.com/dmlab-work/module-typesense-search/actions/workflows/ci.yml) [![codecov](https://codecov.io/gh/dmlab-work/module-typesense-search/graph/badge.svg)](https://codecov.io/gh/dmlab-work/module-typesense-search) [![Packagist Version](https://img.shields.io/packagist/v/dmlab/module-typesense-search)](https://packagist.org/packages/dmlab/module-typesense-search) [![Downloads](https://img.shields.io/packagist/dt/dmlab/module-typesense-search)](https://packagist.org/packages/dmlab/module-typesense-search) [![PHP](https://img.shields.io/packagist/dependency-v/dmlab/module-typesense-search/php)](https://packagist.org/packages/dmlab/module-typesense-search) [![License](https://img.shields.io/packagist/l/dmlab/module-typesense-search)](LICENSE) ![Magento](https://img.shields.io/badge/Magento-2.4-orange)

Registers **Typesense** as a selectable Magento 2 search engine and implements the query side
(L2 of the Typesense suite): it translates a `Magento\Framework\Search\RequestInterface` into a
Typesense query and returns matched product IDs plus aggregations. A free, uncapped drop-in
replacement for OpenSearch/Elasticsearch.

Since Magento 2.4 the engine also drives category listings and layered-navigation counts, so this
one module serves **quick search, advanced search, category listings and layered navigation**. It
**reads only** — writing documents and patching schema is `typesense-indexer`'s job.

## Install

```bash
composer require dmlab/module-typesense-search   # pulls core + indexer
bin/magento module:enable DmLab_TypesenseCore DmLab_TypesenseIndexer DmLab_TypesenseSearch
bin/magento setup:upgrade
```

## Select Typesense

1. *Stores → Configuration → Catalog → Catalog Search*.
2. Set **Search Engine** to **Typesense** and fill in the connection fields (host / port / protocol /
   API key — owned by `typesense-indexer`). **Test Connection** checks the unsaved form values
   (node health + API key); a masked key falls back to the saved one only for the saved node.
3. Reindex: `bin/magento indexer:reindex catalogsearch_fulltext`.

Selection is admin-only: Magento's `setup:install` accepts only `elasticsearch8|opensearch`.
`setup:upgrade` validates the saved Typesense connection. Switching back to OpenSearch/Elasticsearch
is reversible — pick the other engine and reindex.

## Covered

Quick + advanced search · category listings · layered navigation (facet buckets + counts) · price
ranges / dynamic intervals · native search synonyms (*Marketing → Search Synonyms*, synced by
`typesense-indexer`) · "Did you mean" = one typo-corrected query (`search_suggestion_count` only
enables/disables it). `etc/search_engine.xml` declares only what is implemented.

## Known differences

- Advanced Search by SKU matches a token **prefix** (`WSH1` → `WSH12…`); ES/OpenSearch match `*value*`.
- Position sort with several categories uses the first category id (Typesense has no
  min-over-fields sort).
- Sort/facet clauses on fields missing from the schema (e.g. a category not yet reindexed) are
  dropped and logged, not fatal; a filter on a missing field, or with a value that does not fit the
  field type, returns an empty result.
- The alias schema is cached 5 min and dropped on every alias swap or attribute schema PATCH.
  Synonym-set existence is cached 5 min: a store's first synonym group takes effect within 5 min
  (`bin/magento cache:flush` applies it now).
- Constructs Typesense's grammar cannot express (negated wildcard / compound filters) degrade to an
  empty result rather than a wrong one; standard catalog flows never produce them.

## Extension seams

Both are **di-sorted pipelines**, never a `preference` — so paid modules (merchandising, semantic)
compose instead of colliding. Both ship empty; steps run in `sortOrder`, each receiving the previous
step's output.

`QueryModifierInterface::modify(array $query, RequestInterface $request): array` — rewrites the
Typesense search payload (already carrying `facet_by`). Sort/facet clauses on missing fields are
dropped afterwards; modifier params also reach price statistics.

```xml
<type name="DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier">
    <arguments>
        <argument name="modifiers" xsi:type="array">
            <item sortOrder="10" name="merchandising" xsi:type="object">Vendor\Module\Query\MyModifier</item>
        </argument>
    </arguments>
</type>
```

`ResponseProcessorInterface::process(array $response, array $params, RequestInterface $request): array`
— rewrites the raw Typesense response before hits and facets are mapped.

```xml
<type name="DmLab\TypesenseSearch\SearchAdapter\Response\ResponseProcessor">
    <arguments>
        <argument name="processors" xsi:type="array">
            <item sortOrder="10" name="merchandising" xsi:type="object">Vendor\Module\Response\MyProcessor</item>
        </argument>
    </arguments>
</type>
```

## Requirements

Magento 2.4.9+ (`magento/framework ^103.0.9`) · PHP 8.3–8.5 · Typesense ≥ 30.0 ·
`dmlab/module-typesense-core` and `dmlab/module-typesense-indexer` `^0.2.0` (the indexer owns the
connection settings).

## Integration tests

`Test/Integration/` runs against a real Typesense (throw-away `it_search_*` collections) and is
skipped unless `TYPESENSE_TEST_URL` and `TYPESENSE_TEST_API_KEY` are set:

```bash
docker exec -w /var/www/html/m2-modules/module-typesense-search -e MAGENTO_ROOT=/var/www/html \
  -e TYPESENSE_TEST_URL=http://typesense:8108 -e TYPESENSE_TEST_API_KEY=<key> \
  magento-app php /var/www/html/vendor/bin/phpunit -c Test/Integration/phpunit.xml
```

## License

OSL-3.0 © DMLab.
