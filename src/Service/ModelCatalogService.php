<?php

declare(strict_types=1);

namespace App\Service;

use function in_array;
use function is_array;
use function is_string;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * LLM model discovery (docs/model-selection-plan.md §4).
 *
 * Proxies the OpenAI-compatible `GET /v1/models` from the configured LLM
 * endpoint (llama.cpp's llama-server in the reference deployment, but any
 * OpenAI-compatible provider works) and normalizes it to a plain model-name
 * list for the admin UI datalists and the worker-side discovery route.
 *
 * Contract:
 *  - reads `data[].id` only — never trusts other fields (llama.cpp adds
 *    aliases, status, architecture…, all informational);
 *  - dedupes while preserving upstream order;
 *  - upstream failure / malformed body / empty list → `{models: [default],
 *    default}` with NO exception (a dead discovery path must never take
 *    chat traffic down with it — M3c);
 *  - normalized result cached in-process with a TTL knob
 *    (TASKWEAVER_LLM_MODELS_CACHE_TTL; 0 = always fetch).
 *
 * NOTE on "unloaded" models: llama.cpp preset-registered models report
 * `status.value: "unloaded"` until first use — lazy-loaded on the first
 * request naming them. They are valid selectable targets, not errors.
 */
final class ModelCatalogService
{
    private ?array $cache = null;

    private float $cacheExpiresAt = 0.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $llmUrl,
        private readonly string $defaultModel,
        private readonly int $cacheTtl = 300,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The normalized model list + deployment default.
     *
     * @return array{models: string[], default: string}
     */
    public function list(bool $refresh = false): array
    {
        if (!$refresh && null !== $this->cache && $this->cacheExpiresAt > microtime(true)) {
            return $this->cache;
        }

        $models = $this->fetchModels();
        if ([] === $models) {
            $models = [$this->defaultModel];
        } elseif (!in_array($this->defaultModel, $models, true)) {
            // The default must always be selectable in the UI datalists even
            // when the upstream list omits it (e.g. it was renamed there).
            $models[] = $this->defaultModel;
        }

        $result = [
            'models' => $models,
            'default' => $this->defaultModel,
        ];

        $this->cache = $result;
        $this->cacheExpiresAt = microtime(true) + $this->cacheTtl;

        return $result;
    }

    /**
     * @return string[]
     */
    private function fetchModels(): array
    {
        $url = rtrim($this->llmUrl, '/').'/models';

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 5]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (Throwable $e) {
            $this->logger?->warning('Model discovery failed: {error}', ['error' => $e->getMessage()]);

            return [];
        }

        if ($status >= 400) {
            $this->logger?->warning('Model discovery failed: HTTP {status}', [
                'status' => $status,
                'body' => substr($body, 0, 200),
            ]);

            return [];
        }

        $decoded = json_decode($body, true);
        $data = is_array($decoded) ? ($decoded['data'] ?? null) : null;
        if (!is_array($data)) {
            $this->logger?->warning('Model discovery returned malformed body');

            return [];
        }

        $models = [];
        foreach ($data as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null)) {
                continue;
            }
            $id = trim($entry['id']);
            if ('' === $id || in_array($id, $models, true)) {
                continue;
            }
            $models[] = $id;
        }

        return $models;
    }
}
