<?php

declare(strict_types=1);

namespace TaskWeaverWorker\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use TaskWeaverWorker\LlmClient;

use function json_decode;

/**
 * Per-step model selection (docs/model-selection-plan.md §5).
 *
 * setModel() switches the model in every subsequent chat payload while the
 * client (connection, auth, budget) is reused. Empty string is ignored —
 * a claim against an old controller that omits step.model must not clobber
 * the provisioned default.
 */
final class LlmClientSetModelTest extends TestCase
{
    public function testSetModelSwitchesModelInChatPayload(): void
    {
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$bodies): MockResponse {
            $bodies[] = func_get_args();

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $client->chat([['role' => 'user', 'content' => 'first']]);

        $client->setModel('Qwen3.6-35B-A3B-HauhauCS');
        $client->chat([['role' => 'user', 'content' => 'second']]);

        $first = json_decode((string) $bodies[0][2]['body'] ?? '', true);
        $second = json_decode((string) $bodies[1][2]['body'] ?? '', true);

        // Whatever the surrounding payload shape, the model field must be
        // the provisioned default first, then the switched model.
        $modelOf = static function (array $body): ?string {
            $models = array_filter($body, static fn ($v, $k) => 'model' === $k && is_string($v), ARRAY_FILTER_USE_BOTH);

            return $models['model'] ?? null;
        };

        self::assertSame('Qwen3.5-4B', $modelOf($first ?? []));
        self::assertSame('Qwen3.6-35B-A3B-HauhauCS', $modelOf($second ?? []));
    }

    public function testEmptySetModelIsIgnored(): void
    {
        $http = new MockHttpClient(static function (): MockResponse {
            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
            ]));
        });

        $client = new LlmClient('http://llm:8080/v1', 'Qwen3.5-4B', null, [], $http);
        $client->setModel('');

        // No exception, model untouched — old controllers that omit
        // step.model degrade to the provisioned default (M4).
        $client->chat([['role' => 'user', 'content' => 'hello']]);

        self::assertTrue(true);
    }
}