<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\McpServer;
use App\Repository\McpServerRepository;
use App\Service\ConnectionTester;
use App\Service\TagService;
use App\Service\ToolSyncService;

use function array_filter;
use function array_map;
use function array_values;
use function count;

use Doctrine\ORM\EntityManagerInterface;

use function explode;
use function in_array;
use function is_array;
use function sprintf;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function trim;

/**
 * MCP server + tool management (SPEC.md → Admin UI `/tools`).
 *
 * Create/edit/delete MCP servers (OpenAPI or HTTP streamable MCP) and sync
 * their tool definitions. Tool tags are manual: seeded once from the spec on
 * first sync, preserved on later re-syncs. Tools that vanish from a server
 * are flagged removed, not deleted.
 */
#[Route('/tools')]
final class ToolsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ToolSyncService $sync,
        private readonly TagService $tags,
        private readonly ConnectionTester $connectionTester,
    ) {
    }

    #[Route('', name: 'app_tools', methods: ['GET'])]
    public function index(McpServerRepository $servers): Response
    {
        return $this->render('admin/tools.html.twig', [
            'servers' => $servers->findBy([], ['name' => 'ASC']),
        ]);
    }

    #[Route('/test-connection', name: 'app_tool_server_test', methods: ['POST'])]
    public function testConnection(Request $request): JsonResponse
    {
        // Validate the submitted settings without persisting anything.
        // Credentials are env var NAMES only — the browser never sends
        // secret values; ConnectionTester resolves them from the env at
        // call time exactly like a real sync.
        return $this->json($this->connectionTester->test($request->request->all()));
    }

    #[Route('/new', name: 'app_tool_server_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $server = new McpServer('', McpServer::TRANSPORT_OPENAPI, '');
        $errors = [];
        $syncResult = null;
        $syncError = null;

        if ($request->isMethod('POST')) {
            $errors = $this->applyForm($server, $request->request->all());

            if ([] === $errors) {
                $this->em->persist($server);
                $this->em->flush();

                // Discover tools right away so the list is populated.
                try {
                    $syncResult = $this->sync->sync($server);
                    $this->em->flush();
                } catch (Throwable $e) {
                    $syncError = $e->getMessage();
                }

                $this->addFlash('success', sprintf('MCP server "%s" created.', $server->getName()));
                if (null !== $syncError) {
                    $this->addFlash('error', sprintf('Server created, but tool sync failed: %s', $syncError));
                }

                return $this->redirectToRoute('app_tool_server_show', ['id' => $server->getId()->toRfc4122()]);
            }
        }

        return $this->render('admin/tools/server_form.html.twig', [
            'server' => $server,
            'errors' => $errors,
            'is_new' => true,
            'sync_result' => $syncResult,
            'sync_error' => $syncError,
        ]);
    }

    #[Route('/{id}', name: 'app_tool_server_show', methods: ['GET'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function show(string $id, McpServerRepository $servers): Response
    {
        $server = $servers->find(Uuid::fromString($id)->toRfc4122());
        if (!$server instanceof McpServer) {
            throw $this->createNotFoundException('MCP server not found');
        }

        return $this->render('admin/tools/show.html.twig', [
            'server' => $server,
            'all_tags' => $this->tags->allKnown(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_tool_server_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function edit(Request $request, string $id, McpServerRepository $servers): Response
    {
        $server = $servers->find(Uuid::fromString($id)->toRfc4122());
        if (!$server instanceof McpServer) {
            throw $this->createNotFoundException('MCP server not found');
        }

        $errors = [];
        $syncResult = null;
        $syncError = null;

        if ($request->isMethod('POST')) {
            // Capture the pre-edit tool set so we can report the sync delta
            // against what changed on this save.
            $before = $this->toolNames($server);

            $errors = $this->applyForm($server, $request->request->all());

            if ([] === $errors) {
                // The form's tool tag edits are applied to the persisted rows
                // (manual tagging is the point of the tools UI).
                $this->applyTagEdits($server, $request->request->all());
                $this->em->flush();

                if ($request->request->getBoolean('resync')) {
                    try {
                        $syncResult = $this->sync->sync($server);
                        $this->em->flush();
                    } catch (Throwable $e) {
                        $syncError = $e->getMessage();
                    }
                } else {
                    $syncResult = ['total' => count($before), 'created' => 0, 'updated' => 0, 'removed' => 0, 'restored' => 0];
                }

                $this->addFlash('success', sprintf('MCP server "%s" updated.', $server->getName()));
                if (null !== $syncError) {
                    $this->addFlash('error', sprintf('Server updated, but tool sync failed: %s', $syncError));
                }

                return $this->redirectToRoute('app_tool_server_show', ['id' => $server->getId()->toRfc4122()]);
            }
        }

        return $this->render('admin/tools/server_form.html.twig', [
            'server' => $server,
            'errors' => $errors,
            'is_new' => false,
            'sync_result' => $syncResult,
            'sync_error' => $syncError,
        ]);
    }

    #[Route('/{id}/sync', name: 'app_tool_server_sync', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function sync(string $id, McpServerRepository $servers): Response
    {
        $server = $servers->find(Uuid::fromString($id)->toRfc4122());
        if (!$server instanceof McpServer) {
            throw $this->createNotFoundException('MCP server not found');
        }

        try {
            $result = $this->sync->sync($server);
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'Synced "%s": %d tools (%d new, %d updated, %d removed, %d restored).',
                $server->getName(),
                $result['total'],
                $result['created'],
                $result['updated'],
                $result['removed'],
                $result['restored'],
            ));
        } catch (Throwable $e) {
            $this->addFlash('error', sprintf('Sync failed: %s', $e->getMessage()));
        }

        return $this->redirectToRoute('app_tool_server_show', ['id' => $server->getId()->toRfc4122()]);
    }

    #[Route('/{id}/delete', name: 'app_tool_server_delete', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function delete(string $id, McpServerRepository $servers): Response
    {
        $server = $servers->find(Uuid::fromString($id)->toRfc4122());
        if (!$server instanceof McpServer) {
            throw $this->createNotFoundException('MCP server not found');
        }

        $name = $server->getName();
        $this->em->remove($server); // orphanRemoval deletes its ToolDefs
        $this->em->flush();

        $this->addFlash('success', sprintf('MCP server "%s" deleted.', $name));

        return $this->redirectToRoute('app_tools');
    }

    #[Route('/{id}/tags', name: 'app_tool_server_save_tags', methods: ['POST'], requirements: ['id' => '[0-9a-fA-F-]{36}'])]
    public function saveTags(string $id, Request $request, McpServerRepository $servers): Response
    {
        $server = $servers->find(Uuid::fromString($id)->toRfc4122());
        if (!$server instanceof McpServer) {
            throw $this->createNotFoundException('MCP server not found');
        }

        $raw = $request->request->all('tool_tags');
        $updated = 0;
        foreach ($server->getToolDefs() as $tool) {
            if (!isset($raw[$tool->getId()->toRfc4122()])) {
                continue;
            }
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $raw[$tool->getId()->toRfc4122()]))));
            $tool->setTags($tags);
            ++$updated;
        }
        $this->em->flush();

        $this->addFlash('success', sprintf('Tags for %d tool(s) on "%s" updated.', $updated, $server->getName()));

        return $this->redirectToRoute('app_tool_server_show', ['id' => $server->getId()->toRfc4122()]);
    }

    /**
     * @return array<string, string>
     */
    private function applyForm(McpServer $server, array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ('' === $name) {
            $errors['name'] = 'Server name is required.';
        }
        $server->setName($name);

        $endpoint = trim((string) ($data['endpoint'] ?? ''));
        if ('' === $endpoint) {
            $errors['endpoint'] = 'Endpoint is required.';
        } elseif (!str_starts_with($endpoint, 'http://') && !str_starts_with($endpoint, 'https://')) {
            $errors['endpoint'] = 'Endpoint must start with http:// or https://.';
        }
        $server->setEndpoint($endpoint);

        $transport = (string) ($data['transport'] ?? McpServer::TRANSPORT_OPENAPI);
        if (!in_array($transport, McpServer::TRANSPORTS, true)) {
            $errors['transport'] = 'Unsupported transport.';
        } else {
            $server->setTransport($transport);
        }

        $server->setEnabled(!empty($data['enabled']));
        $server->setDescription(trim((string) ($data['description'] ?? '')) ?: null);

        // Credential env-var names (names only; values stay in the env).
        $credVars = [];
        if (isset($data['cred_vars']) && is_array($data['cred_vars'])) {
            foreach ($data['cred_vars'] as $v) {
                $v = trim((string) $v);
                if ('' !== $v) {
                    $credVars[] = $v;
                }
            }
        }
        $server->setCredVars(array_values(array_unique($credVars)));

        return $errors;
    }

    /**
     * Apply per-tool tag edits submitted with the form (name → comma string).
     *
     * @param array<string, mixed> $data
     */
    private function applyTagEdits(McpServer $server, array $data): void
    {
        $raw = $data['tool_tags'] ?? [];
        if (!is_array($raw)) {
            return;
        }

        foreach ($server->getToolDefs() as $tool) {
            $submitted = $raw[$tool->getId()->toRfc4122()] ?? null;
            if (null === $submitted) {
                continue;
            }
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $submitted))));
            $tool->setTags($tags);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function toolNames(McpServer $server): array
    {
        $names = [];
        foreach ($server->getToolDefs() as $tool) {
            $names[$tool->getName()] = true;
        }

        return $names;
    }
}
