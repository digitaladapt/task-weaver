<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TaskWeaverWorker\LlmClient;

use function json_decode;

/**
 * Tool-schema object/array disambiguation for the LLM payload.
 *
 * A no-argument tool's `properties` is an empty map that PHP can only carry
 * as `[]` once the schema has been through a JSON round-trip (DB storage /
 * controller→worker hop). Encoded naively it becomes `"properties":[]`, and
 * strict JSON-Schema validators (llama.cpp) reject the whole request with
 * "JSON schema error at #: properties must be an object".
 *
 * The client must re-encode empty JSON-Schema object keywords as `{}` at the
 * serialization boundary — this is where the distinction can still be fixed.
 */
final class LlmClientToolSchemaTest extends TestCase
{
    /**
     * @return array{0: array<string, mixed>, 1: LlmClient}
     */
    private function capturePayload(array $tools): array
    {
        $captured = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = json_decode((string) $options['body'], true);

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $client->chat([['role' => 'user', 'content' => 'hello']], $tools);

        return [$captured[0], $client];
    }

    private function rawBody(array $tools): string
    {
        $raw = '';
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$raw): MockResponse {
            $raw = (string) $options['body'];

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $client->chat([['role' => 'user', 'content' => 'hello']], $tools);

        return $raw;
    }

    public function testEmptyPropertiesSerializeAsJsonObject(): void
    {
        $tools = [[
            'name' => 'demo.time',
            'description' => 'Returns the current time.',
            // Shape after a DB/HTTP round-trip: empty map decodes as [].
            'schema' => ['type' => 'object', 'properties' => []],
        ]];

        $raw = $this->rawBody($tools);

        // The wire form must be an object: "properties":{}
        self::assertStringContainsString('"properties":{}', $raw);
        self::assertStringNotContainsString('"properties":[]', $raw);
    }

    public function testPopulatedPropertiesSurviveUnchanged(): void
    {
        $tools = [[
            'name' => 'demo.echo',
            'description' => 'Echo.',
            'schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => ['message'],
            ],
        ]];

        [$payload] = $this->capturePayload($tools);

        $params = $payload['tools'][0]['function']['parameters'];
        self::assertSame(['message' => ['type' => 'string']], $params['properties']);
        self::assertSame(['message'], $params['required']);
    }

    public function testNestedEmptyPropertiesAlsoNormalized(): void
    {
        $tools = [[
            'name' => 'nested',
            'description' => 'Nested object.',
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'options' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],
        ]];

        $raw = $this->rawBody($tools);

        self::assertStringNotContainsString('"properties":[]', $raw);
        // Only the inner map is empty; the outer one carries `options`.
        self::assertSame(1, substr_count($raw, '"properties":{}'));
        self::assertStringContainsString('"properties":{"options":', $raw);
    }

    public function testNonSchemaArraysAreNotObjectified(): void
    {
        // A genuine JSON array (e.g. `required`) must stay an array even when
        // empty. Only JSON-Schema object keywords are objectified.
        $tools = [[
            'name' => 'demo.echo',
            'description' => 'Echo.',
            'schema' => [
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => [],
            ],
        ]];

        $raw = $this->rawBody($tools);

        self::assertStringContainsString('"required":[]', $raw);
    }
}
