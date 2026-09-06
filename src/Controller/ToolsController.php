<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\McpServerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only MCP server / tool browsing (SPEC.md → Admin UI).
 */
#[Route('/tools')]
final class ToolsController extends AbstractController
{
    #[Route('', name: 'app_tools', methods: ['GET'])]
    public function index(McpServerRepository $servers): Response
    {
        return $this->render('admin/tools.html.twig', [
            'servers' => $servers->findBy([], ['name' => 'ASC']),
        ]);
    }
}
