<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiKeyRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Validates the X-API-Key against the stored hash on CheckPassportEvent.
 *
 * Keeping the failure here (instead of in ApiKeyAuthenticator::authenticate())
 * means a bad key takes the same path as a bad password: the firewall's
 * LoginThrottlingListener consumes a token on CheckPassportEvent, so the
 * max-5-per-minute limit actually applies to /api/auth/verify.
 */
final class ApiKeyCheckListener
{
    public function __construct(
        private readonly ApiKeyRepository $apiKeyRepository,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();

        if (!$passport instanceof SelfValidatingPassport || !$passport->hasBadge(UserBadge::class)) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        $apiKey = null !== $request ? (string) $request->headers->get('X-API-Key', '') : '';

        $stored = $this->apiKeyRepository->findFirst();
        $valid = null !== $stored && password_verify($apiKey, $stored->getKeyHash());

        if (!$valid) {
            throw new CustomUserMessageAuthenticationException('Invalid API key');
        }
    }
}
