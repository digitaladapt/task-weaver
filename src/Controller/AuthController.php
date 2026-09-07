<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ApiKey;
use App\Repository\ApiKeyRepository;
use Doctrine\ORM\EntityManagerInterface;

use const PASSWORD_BCRYPT;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Setup + login for the admin UI (penny-track pattern).
 *
 * /setup  — shown exactly once on a fresh install; generates the key,
 *           shows it once, operator saves it (browser localStorage).
 * /login  — paste the key; verified then stored in localStorage.
 * /api/auth/verify — the browser's bootstrap call: presents the stored
 *           key as X-API-Key, the firewall authenticates it and (because
 *           the main firewall is stateful) establishes the session cookie
 *           that carries auth for the server-rendered admin pages.
 * /api/auth/status — reports whether setup has been completed.
 */
#[AsController]
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeys,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/setup', name: 'app_setup', methods: ['GET'])]
    public function setup(): Response
    {
        if ($this->apiKeys->hasAny()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('auth/setup.html.twig');
    }

    #[Route('/api/auth/setup', name: 'api_auth_setup', methods: ['POST'])]
    public function apiSetup(): JsonResponse
    {
        if ($this->apiKeys->hasAny()) {
            return new JsonResponse(['error' => 'Already configured'], Response::HTTP_CONFLICT);
        }

        // Generate -> hash -> persist. Plaintext is returned exactly once
        // and never stored anywhere server-side.
        $key = bin2hex(random_bytes(32));
        $hash = password_hash($key, PASSWORD_BCRYPT);

        $this->em->persist(new ApiKey($hash));
        $this->em->flush();

        return new JsonResponse(['api_key' => $key]);
    }

    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(): Response
    {
        if (!$this->apiKeys->hasAny()) {
            return $this->redirectToRoute('app_setup');
        }

        return $this->render('auth/login.html.twig');
    }

    /**
     * Requires ROLE_ADMIN (access_control): reaching this method at all
     * means the X-API-Key header was valid and the session is now
     * established. The response confirms it for the frontend.
     */
    #[Route('/api/auth/verify', name: 'api_auth_verify', methods: ['POST'])]
    public function verify(): JsonResponse
    {
        $key = $this->apiKeys->findFirst();
        if (null !== $key) {
            $key->markUsed();
            $this->em->flush();
        }

        return new JsonResponse(['valid' => true]);
    }

    #[Route('/api/auth/status', name: 'api_auth_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse(['configured' => $this->apiKeys->hasAny()]);
    }
}
