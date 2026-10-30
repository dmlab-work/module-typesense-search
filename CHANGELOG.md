# Changelog

## 0.2.1

- Supports Magento 2.4.7+ (`magento/framework ^103.0.7`) and PHP 8.2–8.5.
- Search failures throw `ClientException` on 2.4.9 and `\RuntimeException` before it.
- CI runs on PHP 8.2–8.5; CI installs are no longer counted as Packagist downloads.
- composer.json: keywords, homepage, support, suggest.
- README rewritten: screenshot, Hyvä, Hosting.

## 0.2.0

- Requires Magento 2.4.9+ (`magento/framework ^103.0.9`), the Typesense suite `^0.2.0` and Typesense ≥ 30.0.
- Fixed:
  - HTTP 500 on Yes/No filters;
  - HTTP 500 when a sort/facet field is missing (new category, customer group, website);
  - empty GraphQL category aggregation;
  - Advanced Search by SKU returning nothing.
- Added: native search synonyms; admin Test Connection button; `ResponseProcessorInterface` seam.
- Changed:
  - query modifiers now see facet params, and modifier params reach price statistics;
  - "Did you mean" suggests the corrected query;
  - the Elasticsearch attribute-plugin neutralizer moved here from the indexer.
- Removed: unreachable `setup:install` integration.

## 0.1.1

- Requires the sibling Typesense modules `^0.1.1`.

## 0.1.0

- First release under the `dmlab` vendor and `DmLab` namespace.
