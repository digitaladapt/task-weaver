<?php

declare(strict_types=1);

namespace App\Service;

use function is_array;
use function is_string;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\MarkdownConverter;

use function sprintf;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Renders JSON payloads/results for the admin UI.
 *
 * The problem: event payloads and step results are pretty-printed JSON in a
 * <pre> with horizontal overflow — a single long string leaf (usually
 * markdown from an LLM/tool) becomes an unreadable one-line wall.
 *
 * The approach (Option A, as agreed):
 *  1. Walk the payload and find every STRING LEAF that is "rich" (contains
 *     markdown markers, escaped characters, or multiple lines).
 *  2. Those leaves are REMOVED from the pretty-printed JSON (replaced with a
 *     compact placeholder marker) — no duplicated content on the page.
 *  3. Each rich leaf is converted markdown → HTML, sanitized (allowlist; raw
 *     HTML from tool output is escaped first, then the generated HTML is
 *     filtered again), and listed in a separate "rendered" section keyed by
 *     its JSON path (e.g. `result.content`, `events.0.payload.result`).
 *
 * JSON view keeps every other leaf intact (numbers, booleans, arrays, short
 * strings, URLs, terminal output, …) and wraps instead of overflowing.
 */
final class PayloadRenderer
{
    /** Leaves at least this long are candidates for markdown detection. */
    public const DEFAULT_RICH_THRESHOLD = 100;

    private readonly MarkdownConverter $converter;
    private readonly HtmlSanitizer $sanitizer;

    public function __construct(
        private readonly int $richThreshold = self::DEFAULT_RICH_THRESHOLD,
    ) {
        // GFM (tables, strikethrough, task lists, autolinks). Raw HTML in the
        // markdown source is ESCAPED first (html_input: escape) so a tool
        // result can never inject markup through the markdown converter;
        // whatever the converter emits is then run through the allowlist
        // sanitizer as defense in depth.
        $this->converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        $this->sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowElement('a', ['href', 'title'])
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->forceAttribute('a', 'rel', 'noopener noreferrer')
                ->allowElement('img', ['src', 'alt', 'title'])
                ->allowMediaSchemes(['https', 'http'])
                ->allowRelativeLinks()
        );
    }

    /**
     * Split a decoded JSON value into:
     *   - `json`: the value with rich leaves elided (placeholder markers);
     *   - `sections`: ordered rich-leaf entries (path → rendered HTML).
     *
     * @param array<string, mixed>|null $value
     * @param string                    $label context prefix for section paths
     *                                         (e.g. "event.payload", "result")
     *
     * @return array{json: array<string, mixed>, sections: array<int, array{path: string, html: string}>}
     */
    public function prepare(?array $value, string $label = ''): array
    {
        if (null === $value || [] === $value) {
            return ['json' => [], 'sections' => []];
        }

        $sections = [];
        $json = $this->walk($value, $label, $sections);

        return ['json' => $json, 'sections' => $sections];
    }

    /**
     * @param array<string, mixed>                          $value
     * @param array<int, array{path: string, html: string}> $sections
     *
     * @return array<string, mixed>
     */
    private function walk(array $value, string $path, array &$sections): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $nodePath = '' === $path ? (string) $key : $path.'.'.$key;

            if (is_string($item)) {
                if ($this->isRich($item)) {
                    $sections[] = [
                        'path' => $nodePath,
                        'html' => $this->renderMarkdown($item),
                    ];
                    // Compact marker: the page shows the leaf in the rendered
                    // section only; keep a hint in the JSON view so the shape
                    // stays obvious (no giant string duplicated on the page).
                    $out[$key] = sprintf('[rendered — see section "%s"]', $nodePath);

                    continue;
                }

                $out[$key] = $item;

                continue;
            }

            if (is_array($item)) {
                $out[$key] = $this->walk($item, $nodePath, $sections);

                continue;
            }

            // Scalars (int/float/bool/null) pass through untouched.
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * A string leaf is "rich" when it is long enough to be a content blob AND
     * carries evidence of markdown/formatting: markdown markers, escaped
     * entities, or structure (newlines, lists). Plain long URLs and terminal
     * logs deliberately stay in the JSON view (they wrap fine once the <pre>
     * wraps).
     */
    private function isRich(string $value): bool
    {
        if (mb_strlen($value) < $this->richThreshold) {
            return false;
        }

        // Structural / markdown evidence.
        if (str_contains($value, "\n")) {
            return true;
        }

        // Heading / emphasis / code / blockquote / list / table / link / task markers.
        if (1 === preg_match('/(?:^|\n)\s{0,3}(?:#{1,6}\s|\*\*\S|__\S|`{1,3}\S|-\s|\*\s|>\s|\|.+\|)/um', $value)) {
            return true;
        }

        // Inline markdown emphasis / links / strikethrough within one paragraph.
        if (1 === preg_match('/\*\*[^*]{2,}\*\*|__[^_]{2,}__|\[\S[^\]]*\]\(\S+\)|~~[^~]+~~|`{1,3}[^`]+`{1,3}/u', $value)) {
            return true;
        }

        // Escaped characters (e.g. &amp;, &#39;, \n, tabs) inside long values
        // are render-noise: they were escaped to survive JSON, and look
        // better displayed as text/formatting.
        if (1 === preg_match('/&[a-z]+;|&#\d+;|\\\\[ntr"\\\\]/', $value)) {
            return true;
        }

        // Raw HTML/XML-looking markup in tool output (scripts, bold tags,
        // entity-encoded content). The markdown converter escapes it, then
        // the sanitizer strips anything dangerous — so it renders as inert
        // text rather than a wall of raw markup.
        if (1 === preg_match('~<[a-zA-Z!/][^>]*>~u', $value)) {
            return true;
        }

        return false;
    }

    private function renderMarkdown(string $markdown): string
    {
        $html = (string) $this->converter->convert($markdown);

        // Defense in depth: the output allowlist drops any residual markup
        // (event handlers, unsafe URLs, unknown tags) that escaped conversion.
        return trim($this->sanitizer->sanitize($html));
    }
}
