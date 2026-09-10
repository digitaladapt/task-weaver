<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\LlmProxyController;
use App\Entity\Worker;
use App\Repository\WorkerRepository;
use App\Service\WorkerAuthService;

use function assert;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * LLM proxy streaming relay (STREAMING.md §4.2).
 *
 * When the worker sends `stream: true`, the controller must relay upstream
 * SSE frames verbatim (the worker assembles deltas itself), still clamp
 * max_tokens, and keep the buffered JSON relay for non-streaming payloads.
 *
 * The controller is exercised directly with a MockHttpClient that yields a
 * chunked iterable body — no network, no container boot needed. Worker-key
 * auth is a real WorkerAuthService over a stubbed repository; the envelope
 * wiring (services.yaml) is covered by WorkerProvisionLlmProxyTest.
 */
final class LlmProxyControllerStreamingTest extends TestCase
{
    private function controller(HttpClientInterface $http): LlmProxyController
    {
        $container = new Container();
        $bag = new class {
            public function get(string $name): mixed
            {
                return match ($name) {
                    'llm_proxy_upstream_url' => 'https://upstream.example/v1/chat/completions',
                    'llm_api_key' => 'sk-proj-provider-key',
                    'llm_proxy_output_buffer' => 1500,
                    default => null,
                };
            }
        };
        $provider = new class($bag) implements ServiceProviderInterface {
            public function __construct(private readonly object $bag)
            {
            }

            public function get(string $id): mixed
            {
                return $this->bag->get($id);
            }

            public function has(string $id): bool
            {
                return true;
            }

            public function getProvidedServices(): array
            {
                return [];
            }
        };
        $container->set('parameter_bag', $provider);

        $controller = new LlmProxyController($http);
        $controller->setContainer($container);

        return $controller;
    }

    private function auth(): WorkerAuthService
    {
        // Only findByApiKey is exercised; the parent constructor needs a
        // ManagerRegistry which we never use, so stub it.
        $repo = $this->createStub(WorkerRepository::class);
        $repo->method('findByApiKey')
            ->willReturnCallback(static fn (string $apiKey): ?Worker => 'worker-key' === $apiKey ? new Worker('stream-worker', ['terminal']) : null);

        return new WorkerAuthService($repo);
    }

    private function request(bool $stream): Request
    {
        $payload = [
            'model' => 'Qwen3.5-4B',
            'messages' => [['role' => 'user', 'content' => 'hi']],
        ];
        if ($stream) {
            $payload['stream'] = true;
        }

        return Request::create('/api/worker/llm', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer worker-key',
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($payload));
    }

    public function testStreamingPayloadStreamsSseFrames(): void
    {
        $frames = [
            "data: {\"choices\":[{\"delta\":{\"content\":\"Hel\"}}]}\n\n",
            "data: {\"choices\":[{\"delta\":{\"content\":\"lo\"}}]}\n\n",
            "data: [DONE]\n\n",
        ];

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse($frames, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $response = $this->controller($http)->proxy($this->request(true), $this->auth());
        assert($response instanceof StreamedResponse);

        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        $body = $this->captureStreamed($response);

        self::assertStringContainsString('"content":"Hel"', $body);
        self::assertStringContainsString('"content":"lo"', $body);
        self::assertStringContainsString('[DONE]', $body);
    }

    public function testNonStreamingPayloadKeepsBufferedJsonRelay(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'choices' => [['message' => ['content' => 'buffered-ok'], 'finish_reason' => 'stop']],
        ])));

        $response = $this->controller($http)->proxy($this->request(false), $this->auth());

        self::assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('buffered-ok', (string) $response->getContent());
    }

    /**
     * Capture a StreamedResponse's echoed content despite internal
     * ob_flush() calls, by installing an output handler that stores chunks
     * and suppresses them from the real output.
     */
    private function captureStreamed(StreamedResponse $response): string
    {
        $captured = '';
        ob_start(static function (string $chunk) use (&$captured): string {
            $captured .= $chunk;

            return '';
        });
        try {
            $response->sendContent();
        } finally {
            ob_end_flush();
        }

        return $captured;
    }

    public function testUpstreamErrorIsRelayedAsEventStreamError(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"error":"provider boom"}', [
            'response_headers' => ['content-type' => 'application/json'],
            'http_code' => 502,
        ]));

        $response = $this->controller($http)->proxy($this->request(true), $this->auth());
        assert($response instanceof StreamedResponse);

        $body = $this->captureStreamed($response);

        self::assertStringContainsString('event: error', $body);
        self::assertStringContainsString('provider boom', $body);
    }
}
