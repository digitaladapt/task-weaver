<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\McpServer;
use App\Entity\ToolDef;
use App\MCP\McpClientRegistry;

use function array_key_exists;
use function count;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps a server's ToolDef rows in sync with what the server actually
 * exposes (create/update) — the tool management half of the admin UI.
 *
 * Sync semantics (SPEC.md → Tool Management):
 *   - New tools     → ToolDef rows are created. Tags are seeded from the
 *                     server's own spec (OpenAPI operation tags) ONLY here.
 *   - Existing tools→ only the definition (schema, description, name) is
 *                     refreshed. Tags are NOT touched — they are manual and
 *                     preserved across re-syncs.
 *   - Vanished tools→ flagged removed (removed_at set) rather than deleted,
 *                     so manual tags and history survive; a re-appearance on
 *                     a later sync clears the flag (restore).
 */
final class ToolSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly McpClientRegistry $clients,
    ) {
    }

    /**
     * Discover the server's current tools and reconcile the ToolDef rows.
     *
     * @return array{total: int, created: int, updated: int, removed: int, restored: int, errors?: string[]}
     */
    public function sync(McpServer $server): array
    {
        $discovered = $this->clients->listTools($server);

        $byName = [];
        foreach ($discovered as $tool) {
            $byName[$tool->name] = $tool;
        }

        $created = 0;
        $updated = 0;
        $restored = 0;
        $removed = 0;

        $now = new DateTimeImmutable();
        $existingByName = $this->indexByName($server);

        foreach ($byName as $name => $discoveredTool) {
            $existing = $existingByName[$name] ?? null;

            if (null === $existing) {
                // New tool — seed tags from the server's spec.
                $tool = new ToolDef($name, $discoveredTool->tags, $discoveredTool->schema);
                $tool->setDescription($discoveredTool->description);
                $server->addToolDef($tool);
                $this->em->persist($tool);
                ++$created;
                continue;
            }

            // Existing tool — refresh definition, PRESERVE manual tags.
            $changed = false;

            if ($existing->getDescription() !== $discoveredTool->description) {
                $existing->setDescription($discoveredTool->description);
                $changed = true;
            }
            if ($existing->getSchema() !== $discoveredTool->schema) {
                $existing->setSchema($discoveredTool->schema);
                $changed = true;
            }

            if ($existing->isRemoved()) {
                // The tool came back — clear the removal flag and refresh.
                $existing->restore();
                $changed = true;
                ++$restored;
            } elseif ($changed) {
                ++$updated;
            }
        }

        // Flag tools that are no longer defined as removed (never delete).
        foreach ($existingByName as $name => $tool) {
            if (array_key_exists($name, $byName)) {
                continue;
            }
            if (!$tool->isRemoved()) {
                $tool->markRemoved($now);
                ++$removed;
            }
        }

        $this->em->flush();

        return [
            'total' => count($byName),
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
            'restored' => $restored,
        ];
    }

    /**
     * @return array<string, ToolDef> map of tool name → ToolDef for the server
     */
    private function indexByName(McpServer $server): array
    {
        $index = [];
        foreach ($server->getToolDefs() as $tool) {
            $index[$tool->getName()] = $tool;
        }

        return $index;
    }
}
