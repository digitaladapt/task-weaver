<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The single admin user. No credentials live on it — the API key hash
 * does the proving; this object just represents "authenticated admin".
 */
class ApiKeyUser implements UserInterface
{
    public function getRoles(): array
    {
        return ['ROLE_USER', 'ROLE_ADMIN'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'admin';
    }
}
