# DmLab_TypesenseSearch

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
   API key — owned by `typesense-indexer`). The connection is validated on save.
3. Reindex: `bin/magento indexer:reindex catalogsearch_fulltext`.

Switching back to OpenSearch/Elasticsearch is reversible — pick the other engine and reindex.
`setup:install --search-engine=typesense --typesense-nodes=<host:port> --typesense-protocol=<http|https>`
works too (the API key is set in admin afterwards, via its encrypted backend).

## Covered

Quick + advanced search · category listings · layered navigation (facet buckets + counts) · price
ranges / dynamic intervals · typo-tolerant suggestions (honouring `catalog/search/search_suggestion_*`).

Not yet: `synonyms` (a later module). Constructs Typesense's grammar cannot express (wildcard /
negated compound filters) degrade to an empty result rather than a wrong one; standard catalog flows
never produce them. `etc/search_engine.xml` declares only what is implemented.

## The `QueryModifierInterface` seam

Query building is a **di-sorted pipeline**, never a `preference` — so paid modules (merchandising,
semantic) compose instead of colliding. The composite ships empty; add a step by merging into the
`modifiers` array in `di.xml`:

```xml
<type name="DmLab\TypesenseSearch\SearchAdapter\Query\QueryModifier">
    <arguments>
        <argument name="modifiers" xsi:type="array">
            <item sortOrder="10" name="merchandising" xsi:type="object">Vendor\Module\Query\MyModifier</item>
        </argument>
    </arguments>
</type>
```

Each `QueryModifierInterface::modify(array $query, RequestInterface $request): array` receives the
previous step's payload; steps run in `sortOrder`.

## Requirements

Magento 2.4.x · PHP 8.3–8.5 · a running Typesense server (connection owned by `typesense-indexer`).

## License

OSL-3.0 © DMLab.
