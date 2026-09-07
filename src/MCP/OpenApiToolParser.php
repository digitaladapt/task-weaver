<?php

declare(strict_types=1);

namespace App\MCP;

use function array_key_exists;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;

/**
 * Turns an OpenAPI document into TaskWeaver's DiscoveredTool list.
 *
 * Each operation becomes one tool:
 *   - name        = operationId (the MCP tool name), falling back to a
 *                   generated `method_path` id when openapi.json lacks one
 *                   (some specs omit operationId).
 *   - tags        = the operation's OpenAPI `tags` (used for *initial* tag
 *                   population only — re-syncs preserve manual tags).
 *   - description = operation description or summary
 *   - schema      = JSON Schema built from the operation's parameters
 *                   (path/query/header/cookie → properties, required) plus
 *                   its requestBody schema (dereferenced). The schema also
 *                   carries a derived `x-mcp` block (HTTP method, path,
 *                   path/query param locations) so the OpenAPI transport
 *                   can invoke the tool without hand-written metadata; an
 *                   explicit `x-mcp` on the operation overrides it.
 *                   ToolResolver strips `x-mcp*` keys before advertising
 *                   schemas to workers/LLMs.
 *
 * $refs are resolved against the document's #/components/schemas (and
 * deeper paths). Unknown/unresolvable refs are left as-is defensively.
 */
final class OpenApiToolParser
{
    public function parse(array $spec): array
    {
        $paths = $spec['paths'] ?? [];
        if (!is_array($paths)) {
            return [];
        }

        $tools = [];
        foreach ($paths as $path => $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ($item as $method => $op) {
                $method = strtolower((string) $method);
                if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'], true)) {
                    continue;
                }
                if (!is_array($op)) {
                    continue;
                }

                $name = $this->operationId($op, $method, (string) $path);
                $tags = $this->stringList($op['tags'] ?? []);
                $description = $this->description($op);

                $schema = $this->buildSchema($op, $spec, $method, (string) $path);

                $tools[] = new DiscoveredTool(
                    name: $name,
                    tags: $tags,
                    schema: $schema,
                    description: $description,
                    raw: $op,
                );
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $op
     */
    private function operationId(array $op, string $method, string $path): string
    {
        if (is_string($op['operationId'] ?? null) && '' !== $op['operationId']) {
            return $op['operationId'];
        }

        // Fallback: method + path, sanitized to a stable identifier.
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $path) ?? 'operation';
        $slug = trim($slug, '_');

        return $method.'_'.$slug;
    }

    /**
     * @param array<string, mixed> $op
     */
    private function description(array $op): ?string
    {
        $d = $op['description'] ?? $op['summary'] ?? null;
        if (is_string($d) && '' !== trim($d)) {
            return $d;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $components
     * @param string              $method the HTTP method of this operation
     * @param string              $path   the raw path template, e.g. "/repos/{owner}/{repo}"
     *
     * @return array<string, mixed>
     */
    private function buildSchema(array $op, array $components, string $method, string $path): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters($op) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = is_string($param['name'] ?? null) ? $param['name'] : '';
            if ('' === $name) {
                continue;
            }

            // A `schema` may be inline, a $ref, or absent (then it's untyped).
            $prop = $this->resolveSchema($param['schema'] ?? null, $components);
            if (!is_array($prop)) {
                $prop = ['type' => 'string'];
            } elseif ([] === $prop) {
                $prop = ['type' => 'string'];
            }

            if (null !== ($param['description'] ?? null) && is_string($param['description'])) {
                $prop['description'] = $param['description'];
            }

            if (!empty($param['required'])) {
                $required[] = $name;
            }

            $properties[$name] = $prop;
        }

        // Request body: merge the schema's properties into the tool schema.
        $bodySchema = $this->requestBodySchema($op, $components);
        if (is_array($bodySchema)) {
            $bodyProps = $bodySchema['properties'] ?? [];
            if (is_array($bodyProps)) {
                foreach ($bodyProps as $name => $prop) {
                    if (array_key_exists($name, $properties)) {
                        continue; // path/query param wins over body property
                    }
                    $properties[$name] = $prop;
                }
            }
            foreach (($bodySchema['required'] ?? []) as $name) {
                if (is_string($name) && !in_array($name, $required, true)) {
                    $required[] = $name;
                }
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];
        if ([] !== $required) {
            $schema['required'] = array_values($required);
        }

        // Attach the transport endpoint metadata the OpenAPI client needs
        // (method/path/param locations), so specs without hand-written
        // `x-mcp` blocks — i.e. every auto-generated one (FastAPI, ...) —
        // still yield invocable tools. An explicit `x-mcp` block on the
        // operation wins per-key.
        $schema['x-mcp'] = $this->deriveEndpointMetadata($op, $method, $path);

        if (is_array($op['x-mcp-server'] ?? null)) {
            $schema['x-mcp-server'] = $op['x-mcp-server'];
        }

        return $schema;
    }

    /**
     * Derive the `x-mcp` endpoint metadata for an operation from the
     * operation itself: the HTTP method, the raw path template, and which
     * parameters live in the path vs the query string. An explicit `x-mcp`
     * block on the operation is merged on top (per-key override) so exotic
     * cases (e.g. `body_param` wrapping) can still be hand-specified.
     *
     * @param array<string, mixed> $op
     *
     * @return array<string, mixed>
     */
    private function deriveEndpointMetadata(array $op, string $method, string $path): array
    {
        $pathParams = [];
        $queryParams = [];

        foreach ($this->parameters($op) as $param) {
            if (!is_array($param)) {
                continue;
            }
            $name = $param['name'] ?? null;
            $in = $param['in'] ?? null;
            if (!is_string($name) || '' === $name) {
                continue;
            }

            if ('path' === $in) {
                $pathParams[] = $name;
            } elseif ('query' === $in) {
                $queryParams[] = $name;
            }
        }

        $mcp = [
            'method' => strtoupper($method),
            'path' => $path,
        ];

        if ([] !== $pathParams) {
            $mcp['path_params'] = $pathParams;
        }
        if ([] !== $queryParams) {
            $mcp['query_params'] = $queryParams;
        }

        // Explicit `x-mcp` overrides derived values per key.
        if (is_array($op['x-mcp'] ?? null)) {
            $mcp = array_merge($mcp, $op['x-mcp']);
        }

        return $mcp;
    }

    /**
     * Flatten an operation's parameters: top-level `parameters` plus any
     * per-method parameters that use $refs (unresolvable ones are skipped).
     *
     * @param array<string, mixed> $op
     *
     * @return array<int, mixed>
     */
    private function parameters(array $op): array
    {
        $raw = $op['parameters'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, mixed> $root the full OpenAPI document (for $refs)
     *
     * @return array<string, mixed>|null
     */
    private function requestBodySchema(array $op, array $root): ?array
    {
        $body = $op['requestBody'] ?? null;
        if (!is_array($body)) {
            return null;
        }

        // requestBody may itself be a $ref (rare) or have content.
        $body = $this->resolveRef($body, $root);

        $content = $body['content'] ?? null;
        if (!is_array($content)) {
            return null;
        }

        // Prefer application/json; fall back to the first available media type.
        $schema = null;
        if (is_array($content['application/json'] ?? null)) {
            $schema = $content['application/json']['schema'] ?? null;
        } elseif (is_array($content['multipart/form-data'] ?? null)) {
            $schema = $content['multipart/form-data']['schema'] ?? null;
        } else {
            foreach ($content as $media) {
                if (is_array($media) && isset($media['schema'])) {
                    $schema = $media['schema'];
                    break;
                }
            }
        }

        if (null === $schema) {
            return null;
        }

        $resolved = $this->resolveSchema($schema, $root);

        return is_array($resolved) ? $resolved : null;
    }

    /**
     * Resolve a schema node, following $refs into components.
     *
     * Recursive schemas (a type that references itself) are handled by a
     * depth budget — past it, remaining $refs are left unresolved rather
     * than looping forever.
     *
     * @param array<string, mixed> $root the full OpenAPI document (for $refs)
     */
    private function resolveSchema(mixed $schema, array $root, int $depth = 0): mixed
    {
        if (!is_array($schema) || $depth > 32) {
            return $schema;
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $resolved = $this->resolveRef($schema, $root, $depth);
            // Preserve sibling annotations (description etc.) atop the ref'd
            // schema — OpenAPI allows them and tools read better with them.
            $siblings = $schema;
            unset($siblings['$ref']);
            if ([] !== $siblings) {
                return array_merge(is_array($resolved) ? $resolved : [], $siblings);
            }

            return $resolved;
        }

        // Recurse into nested structures (allOf/anyOf/oneOf/items/properties).
        foreach (['allOf', 'anyOf', 'oneOf', 'items', 'additionalProperties'] as $key) {
            if (isset($schema[$key])) {
                $schema[$key] = $this->resolveContainer($schema[$key], $root, $depth);
            }
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $k => $prop) {
                $schema['properties'][$k] = $this->resolveSchema($prop, $root, $depth + 1);
            }
        }

        return $schema;
    }

    /**
     * Resolve a schema container that may be a single schema object or a
     * JSON array of schemas (allOf/anyOf/oneOf are lists of schemas).
     */
    private function resolveContainer(mixed $container, array $root, int $depth): mixed
    {
        if (is_array($container)) {
            if ($this->isList($container)) {
                return array_map(
                    fn ($s) => $this->resolveSchema($s, $root, $depth + 1),
                    $container,
                );
            }

            return $this->resolveSchema($container, $root, $depth + 1);
        }

        return $container;
    }

    /**
     * Resolve a single $ref node against the document root.
     *
     * @param array<string, mixed> $node
     * @param array<string, mixed> $root the full OpenAPI document
     *
     * @return array<string, mixed>
     */
    private function resolveRef(array $node, array $root, int $depth = 0): array
    {
        $ref = $node['$ref'] ?? null;
        if (!is_string($ref) || '' === $ref || !str_starts_with($ref, '#/')) {
            return $node;
        }

        // Walk the JSON pointer from the document root: #/components/schemas/Foo ...
        $parts = explode('/', substr($ref, 2));
        $cursor = $root;
        foreach ($parts as $part) {
            $part = str_replace('~1', '/', str_replace('~0', '~', $part));
            if (!is_array($cursor) || !isset($cursor[$part])) {
                return $node; // unresolvable → leave as-is
            }
            $cursor = $cursor[$part];
        }

        if (!is_array($cursor)) {
            return $node;
        }

        // A resolved schema may itself contain $refs — recurse with the
        // guarded builder. The depth budget stops recursive schemas from
        // looping forever; past it the (still-nested) ref is left as-is.
        $resolved = $this->resolveSchema($cursor, $root, $depth + 1);

        return is_array($resolved) ? $resolved : $cursor;
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && '' !== trim($v)) {
                $out[] = $v;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return bool whether the array is a JSON array (list) vs object map
     */
    private function isList(array $value): bool
    {
        if ([] === $value) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
