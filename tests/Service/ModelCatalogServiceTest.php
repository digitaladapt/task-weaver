<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ModelCatalogService;

use function json_encode;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Model discovery (docs/model-selection-plan.md §4.3).
 *
 * The live-shape fixture is PINNED from the reference llama.cpp deployment
 * (zenith.devgnome.com:8080/v1/models, captured 2026-09-16 — §4.1): OpenAI
 * listing shape with per-entry `aliases` arrays, preset-registered models
 * reporting status.value "unloaded" (still valid selectable targets), and
 * `architecture.input_modalities`. Normalization must survive all of it.
 */
final class ModelCatalogServiceTest extends TestCase
{
    /**
     * Pinned live capture (§4.1), one field-rich entry + the default model.
     */
    private const LIVE_CAPTURE = [
        'object' => 'list',
        'data' => [
            [
                'id' => 'Qwen3.6-35B-A3B-HauhauCS',
                'aliases' => ['qwen36', 'big-qwen'],
                'tags' => [],
                'object' => 'model',
                'owned_by' => 'llamacpp',
                'created' => 1789565914,
                'status' => ['value' => 'unloaded', 'args' => ['...'], 'preset' => '...'],
                'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
                'source' => 'preset',
                'can_remove' => false,
            ],
            [
                'id' => 'Qwen3.5-4B',
                'aliases' => [],
                'object' => 'model',
                'owned_by' => 'llamacpp',
                'created' => 1789565914,
                'status' => ['value' => 'loaded'],
                'source' => 'preset',
            ],
        ],
    ];

    public function testNormalizesLiveShapeToListAndDefault(): void
    {
        $catalog = $this->catalog(self::LIVE_CAPTURE);

        $result = $catalog->list();

        self::assertSame(['Qwen3.6-35B-A3B-HauhauCS', 'Qwen3.5-4B'], $result['models']);
        self::assertSame('Qwen3.5-4B', $result['default']);
    }

    public function testUnloadedPresetModelsAreListedAsValidTargets(): void
    {
        // §4.1: status "unloaded" = lazy-load on first use, NOT an error state.
        $catalog = $this->catalog(self::LIVE_CAPTURE);

        $result = $catalog->list();

        self::assertContains('Qwen3.6-35B-A3B-HauhauCS', $result['models']);
    }

    public function testFallbackToDefaultOnlyWhenUpstreamUnreachable(): void
    {
        $catalog = $this->catalogHttp(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['http_code' => 500])));

        $result = $catalog->list();

        // Discovery must never take chat traffic down (M3c).
        self::assertSame(['Qwen3.5-4B'], $result['models']);
        self::assertSame('Qwen3.5-4B', $result['default']);
    }

    public function testFallbackOnConnectionError(): void
    {
        $catalog = $this->catalogHttp(new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'Connection refused'])));

        $result = $catalog->list();

        self::assertSame(['Qwen3.5-4B'], $result['models']);
    }

    public function testUpstreamListedButDefaultMissingFromItStillResolved(): void
    {
        $capture = self::LIVE_CAPTURE;
        $capture['data'] = [$capture['data'][0]]; // default model absent upstream

        $catalog = $this->catalog($capture);

        $result = $catalog->list();

        self::assertSame(['Qwen3.6-35B-A3B-HauhauCS', 'Qwen3.5-4B'], $result['models']);
        self::assertSame('Qwen3.5-4B', $result['default']);
    }

    public function testCachedBetweenCallsAndRefreshBypasses(): void
    {
        $calls = 0;
        $http = new MockHttpClient(static function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse((string) json_encode(self::LIVE_CAPTURE), ['http_code' => 200]);
        });
        $catalog = new ModelCatalogService($http, 'http://llm:8080', 'Qwen3.5-4B', 300);

        $catalog->list();
        $catalog->list();

        self::assertSame(1, $calls, 'second list() must hit the in-process cache');

        $catalog->list(true);

        self::assertSame(2, $calls, '?refresh=1 must bypass the cache');
    }

    private function catalog(array $payload): ModelCatalogService
    {
        $body = (string) json_encode($payload);

        return $this->catalogHttp(new MockHttpClient(
            static fn (): MockResponse => new MockResponse($body, ['http_code' => 200])
        ));
    }

    private function catalogHttp(MockHttpClient $http): ModelCatalogService
    {
        return new ModelCatalogService($http, 'http://llm:8080', 'Qwen3.5-4B', 300);
    }
}
