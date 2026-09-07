<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use function in_array;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Enforces CSRF tokens on every mutating (non-GET) admin request.
 *
 * All admin forms embed the same per-form token:
 *
 *     <input type="hidden" name="_csrf" value="{{ csrf_token('admin') }}">
 *
 * This subscriber runs before the controller and rejects any POST/PUT/
 * PATCH/DELETE to an admin route that doesn't present a valid token.
 * Worker machine-to-machine routes (/api/worker) and the auth endpoints
 * (login/setup, Bearer/header-authenticated, no session) are exempt.
 */
final class CsrfSubscriber implements EventSubscriberInterface
{
    private const SAFE = ['GET', 'HEAD', 'OPTIONS'];

    /** Routes whose POSTs must NOT require a CSRF token. */
    private const EXEMPT = [
        'api_auth_setup',   // single-use setup (fires before any session exists)
        'api_auth_verify',  // header-authenticated bootstrap; not cookie-form auth
    ];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // KernelEvents::REQUEST with priority just above the router so we
        // can see the resolved route name.
        return [
            'kernel.request' => ['onKernelRequest', 24],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getMethod(), self::SAFE, true)) {
            return;
        }

        $route = (string) $request->attributes->get('_route', '');

        if ('' === $route || str_starts_with($route, 'worker_')) {
            return;
        }

        if (in_array($route, self::EXEMPT, true)) {
            return;
        }

        $submitted = (string) $request->request->get('_csrf', '');

        if (!$this->csrf->isTokenValid(new CsrfToken('admin', $submitted))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }
    }
}
