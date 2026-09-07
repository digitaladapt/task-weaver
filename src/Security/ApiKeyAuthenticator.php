<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiKeyRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Authenticates the admin via the X-API-Key header (penny-track pattern).
 *
 * The browser keeps the key in localStorage and attaches it to the
 * session-bootstrap call (/api/auth/verify); the resulting session cookie
 * then carries authentication for server-rendered pages and form POSTs.
 * Any request may also present the header directly (API-style use).
 */
class ApiKeyAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('X-API-Key')
            && '' !== (string) $request->headers->get('X-API-Key');
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $apiKey = (string) $request->headers->get('X-API-Key');

        $apiKeyEntity = $this->apiKeyRepository->findFirst();
        $valid = false;

        if (null !== $apiKeyEntity) {
            $valid = password_verify($apiKey, $apiKeyEntity->getKeyHash());
        }

        if (!$valid) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }

        return new SelfValidatingPassport(new UserBadge('admin', static fn () => new ApiKeyUser()));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Null = continue the request; the session is established by the
        // security system, so the cookie sticks for follow-up requests.
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(
                ['error' => strtr($exception->getMessageKey(), $exception->getMessageData())],
                Response::HTTP_UNAUTHORIZED
            );
        }

        return new RedirectResponse('/login');
    }

    /**
     * Entry point for unauthenticated requests: browsers get bounced to
     * /login (the frontend then re-verifies its stored key); API callers
     * get a plain 401.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['error' => 'authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        return new RedirectResponse('/login');
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->getRequestFormat(), 'json')
            || str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
