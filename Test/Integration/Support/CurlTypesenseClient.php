<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseSearch\Test\Integration\Support;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\TypesenseClient;

/**
 * Core client over plain curl against one node, so integration tests need no Magento config or DI.
 */
class CurlTypesenseClient extends TypesenseClient
{
    private const TIMEOUT = 10;

    /**
     * @param string $baseUrl e.g. `http://localhost:8108`
     * @param string $apiKey admin API key
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey
    ) {
    }

    /**
     * @inheritDoc
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        array $query = [],
        ?int $timeout = null,
        ?int $maxAttempts = null,
        ?bool $replaySafe = null,
        ?int $deadline = null
    ): array {
        $raw = $this->send(
            $method,
            $path,
            $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
            'application/json',
            $query
        );
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new TypesenseException(sprintf('Undecodable Typesense response for %s %s.', $method, $path));
        }

        return $decoded;
    }

    /**
     * @inheritDoc
     */
    public function requestRaw(
        string $method,
        string $path,
        string $body,
        string $contentType,
        array $query = [],
        ?int $timeout = null,
        ?int $maxAttempts = null,
        ?bool $replaySafe = null,
        ?int $deadline = null
    ): string {
        return $this->send($method, $path, $body, $contentType, $query);
    }

    /**
     * Send one request and return the raw body; non-2xx → {@see TypesenseException}.
     *
     * @param string $method
     * @param string $path
     * @param string|null $body
     * @param string $contentType
     * @param array<string,scalar> $query
     * @throws TypesenseException
     */
    private function send(string $method, string $path, ?string $body, string $contentType, array $query): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        if ($query !== []) {
            $query = array_map(
                static fn ($value) => is_bool($value) ? ($value ? 'true' : 'false') : $value,
                $query
            );
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $headers = ['X-TYPESENSE-API-KEY: ' . $this->apiKey];
        if ($body !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        // phpcs:enable Magento2.Functions.DiscouragedFunction

        if (!is_string($response)) {
            throw new TypesenseException(sprintf('Typesense %s %s failed: %s', $method, $path, $error));
        }
        if ($status < 200 || $status >= 300) {
            $decoded = json_decode($response, true);
            $decoded = is_array($decoded) ? $decoded : [];
            throw new TypesenseException(
                (string)($decoded['message'] ?? sprintf('Typesense %s %s returned HTTP %d.', $method, $path, $status)),
                $status,
                $decoded
            );
        }

        return $response;
    }
}
