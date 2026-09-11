<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\PayloadRenderer;

use function array_column;

use PHPUnit\Framework\TestCase;

/**
 * PayloadRenderer: the admin UI's JSON-view / rendered-content split.
 *
 * Rich string leaves (markdown, escaped content, multi-line blobs) are pulled
 * out of the pretty-printed JSON and rendered as sanitized HTML beneath their
 * JSON path. Everything else stays in the JSON view, which now wraps instead
 * of horizontal-scrolling.
 */
final class PayloadRendererTest extends TestCase
{
    private PayloadRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new PayloadRenderer();
    }

    public function testMarkdownLeafIsExtractedAndRendered(): void
    {
        $payload = [
            'tool' => 'weather.get',
            'result' => [
                'content' => "# London Weather\n\n**17°C** and *partly cloudy*. Humidity sits at 62% with a gentle breeze making it comfortable for a stroll in the park.\n\n- Humidity: 62%\n- Wind: gentle\n\n```php\n\$x = 1;\n```",
            ],
        ];

        $prepared = $this->renderer->prepare($payload, 'event.payload');

        // The leaf is elided from the JSON view (no duplication).
        self::assertStringContainsString('[rendered', (string) ($prepared['json']['result']['content'] ?? null));
        self::assertStringNotContainsString('# London', (string) $prepared['json']['result']['content']);

        self::assertCount(1, $prepared['sections']);
        $section = $prepared['sections'][0];
        self::assertSame('event.payload.result.content', $section['path']);
        self::assertStringContainsString('<h1>London Weather</h1>', $section['html']);
        self::assertStringContainsString('<strong>17°C</strong>', $section['html']);
    }

    public function testMultipleMarkdownLeavesProduceMultipleSections(): void
    {
        $payload = [
            'a' => 'Some short text.',
            'summary' => "# Head\n\nBody paragraph with **bold** and *italic*. This is long enough to count as rich content for the renderer to extract it out of the JSON view.",
            'note' => 'Second **markdown** block with a bit more prose to make sure it clears the threshold and is extracted as its own section.',
        ];

        $prepared = $this->renderer->prepare($payload);

        self::assertCount(2, $prepared['sections']);
        self::assertSame(['summary', 'note'], array_column($prepared['sections'], 'path'));
        // Short plain strings stay in the JSON view.
        self::assertSame('Some short text.', $prepared['json']['a']);
    }

    public function testShortAndPlainLeavesStayInJson(): void
    {
        $payload = [
            'count' => 3,
            'flag' => true,
            'short' => 'hello',
            'url' => 'https://example.com/'.str_repeat('x', 200),
        ];

        $prepared = $this->renderer->prepare($payload);

        self::assertSame([], $prepared['sections']);
        self::assertSame(3, $prepared['json']['count']);
        self::assertTrue($prepared['json']['flag']);
        self::assertSame('https://example.com/'.str_repeat('x', 200), $prepared['json']['url']);
    }

    public function testNestedArraysAreTraversed(): void
    {
        $payload = [
            'events' => [
                ['payload' => ['result' => 'Short.']],
                ['payload' => ['result' => "# Deep\n\n**bold** nested content that is definitely longer than one hundred characters to trigger rendering. It lives inside a nested array and must still be found."]],
            ],
        ];

        $prepared = $this->renderer->prepare($payload);

        self::assertCount(1, $prepared['sections']);
        self::assertSame('events.1.payload.result', $prepared['sections'][0]['path']);
        self::assertStringContainsString('<h1>Deep</h1>', $prepared['sections'][0]['html']);
    }

    public function testRawHtmlIsEscapedAndUnsafeLinksStripped(): void
    {
        $payload = [
            'result' => "<script>alert('xss')</script>\n\n**Bold** [click](javascript:alert(1))\n\n".str_repeat('padding to exceed threshold ', 3),
        ];

        $prepared = $this->renderer->prepare($payload);

        self::assertCount(1, $prepared['sections']);
        $html = $prepared['sections'][0]['html'];

        // The script text is escaped to inert characters.
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        // The javascript: href is dropped entirely.
        self::assertStringNotContainsString('javascript:', $html);
        // Safe markdown still renders.
        self::assertStringContainsString('<strong>Bold</strong>', $html);
    }

    public function testEmptyAndNullValueProduceEmptyResult(): void
    {
        self::assertSame(['json' => [], 'sections' => []], $this->renderer->prepare(null));
        self::assertSame(['json' => [], 'sections' => []], $this->renderer->prepare([]));
    }

    public function testEscapedEntitiesAreTreatedAsRich(): void
    {
        $payload = [
            'result' => '&amp;lt;code&amp;gt; some escaped markup &amp;amp; entities '.
                str_repeat('with more text to exceed the detection threshold comfortably ', 3),
        ];

        $prepared = $this->renderer->prepare($payload);

        self::assertCount(1, $prepared['sections']);
        self::assertSame('result', $prepared['sections'][0]['path']);
    }
}
