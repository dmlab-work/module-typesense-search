<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Integration;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseSearch\Test\Integration\Support\CurlTypesenseClient;
use PHPUnit\Framework\TestCase;

/**
 * Creates a throw-away fixture collection, alias and synonym set per test class on a real Typesense.
 *
 * Skipped unless `TYPESENSE_TEST_URL` and `TYPESENSE_TEST_API_KEY` are set.
 */
abstract class TypesenseTestCase extends TestCase
{
    private const SCHEMA_FIELDS = [
        ['name' => 'name', 'type' => 'string', 'sort' => true],
        ['name' => 'sku', 'type' => 'string', 'sort' => true],
        ['name' => 'color', 'type' => 'int32[]', 'facet' => true],
        ['name' => 'color_value', 'type' => 'string[]'],
        ['name' => 'eco_collection', 'type' => 'int32', 'facet' => true],
        ['name' => 'legacy_flag', 'type' => 'bool', 'facet' => true],
        ['name' => 'category_ids', 'type' => 'int64[]', 'facet' => true],
        ['name' => 'visibility', 'type' => 'int32', 'facet' => true],
        ['name' => 'description', 'type' => 'string'],
        ['name' => 'price_[0-9]+_.*', 'type' => 'float', 'facet' => true, 'sort' => true, 'optional' => true],
        ['name' => 'position_category_.*', 'type' => 'int32', 'sort' => true, 'optional' => true],
        ['name' => 'sort_color', 'type' => 'string', 'sort' => true, 'optional' => true],
    ];

    private const DOCUMENTS = [
        [
            'id' => '1', 'name' => 'Wool Sweater', 'sku' => 'WSH12', 'color' => [49], 'color_value' => ['Red'],
            'sort_color' => 'Red', 'eco_collection' => 1, 'legacy_flag' => true, 'category_ids' => [2, 21],
            'visibility' => 4, 'description' => 'warm', 'price_0_1' => 45.0, 'position_category_21' => 1,
        ],
        [
            'id' => '2', 'name' => 'Rain Jacket', 'sku' => 'MJ12', 'color' => [50], 'color_value' => ['Blue'],
            'sort_color' => 'Blue', 'eco_collection' => 0, 'legacy_flag' => false, 'category_ids' => [2, 22],
            'visibility' => 4, 'description' => 'dry', 'price_0_1' => 80.5, 'position_category_22' => 3,
        ],
    ];

    /** @var CurlTypesenseClient|null */
    protected static ?CurlTypesenseClient $client = null;

    /** @var string fixture collection name */
    protected static string $collection = '';

    /** @var string alias of the fixture collection */
    protected static string $alias = '';

    /**
     * @inheritDoc
     */
    public static function setUpBeforeClass(): void
    {
        $url = (string)getenv('TYPESENSE_TEST_URL');
        $apiKey = (string)getenv('TYPESENSE_TEST_API_KEY');
        if ($url === '' || $apiKey === '') {
            self::markTestSkipped('Set TYPESENSE_TEST_URL and TYPESENSE_TEST_API_KEY to run integration tests.');
        }

        self::$client = new CurlTypesenseClient($url, $apiKey);
        self::$collection = 'it_search_' . bin2hex(random_bytes(6));
        self::$alias = self::$collection . '_alias';

        try {
            self::createFixture();
        } catch (\Throwable $e) {
            self::tearDownAfterClass();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public static function tearDownAfterClass(): void
    {
        if (self::$client === null) {
            return;
        }

        self::deleteIgnoringMissing('/synonym_sets/' . self::synonymSetName());
        self::deleteIgnoringMissing('/aliases/' . self::$alias);
        self::deleteIgnoringMissing('/collections/' . self::$collection);
        self::$client = null;
    }

    /**
     * Synonym set the indexer would maintain for the fixture alias.
     */
    protected static function synonymSetName(): string
    {
        return self::$alias . '_synonyms';
    }

    /**
     * Collection, documents, alias and synonym set.
     *
     * @throws TypesenseException
     */
    private static function createFixture(): void
    {
        self::$client->request('POST', '/collections', ['name' => self::$collection, 'fields' => self::SCHEMA_FIELDS]);

        $jsonl = implode("\n", array_map(
            static fn (array $document): string => json_encode($document, JSON_THROW_ON_ERROR),
            self::DOCUMENTS
        ));
        $result = self::$client->requestRaw(
            'POST',
            '/collections/' . self::$collection . '/documents/import',
            $jsonl,
            'text/plain',
            ['action' => 'upsert']
        );
        foreach (explode("\n", trim($result)) as $line) {
            $row = json_decode($line, true);
            if (($row['success'] ?? false) !== true) {
                throw new TypesenseException('Fixture import failed: ' . $line);
            }
        }

        self::$client->request('PUT', '/aliases/' . self::$alias, ['collection_name' => self::$collection]);
        self::$client->request(
            'PUT',
            '/synonym_sets/' . self::synonymSetName(),
            ['items' => [['id' => 'g1', 'synonyms' => ['jumper', 'sweater']]]]
        );
    }

    /**
     * DELETE a resource; a 404 means it is already gone.
     *
     * @param string $path
     * @throws TypesenseException
     */
    private static function deleteIgnoringMissing(string $path): void
    {
        try {
            self::$client->request('DELETE', $path);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }
}
