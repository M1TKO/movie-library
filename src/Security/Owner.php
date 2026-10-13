<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class Owner implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(private readonly string $hash) {}
    public function getUserIdentifier(): string
    {
        return 'owner';
    }
    public function getRoles(): array
    {
        return ['ROLE_OWNER'];
    }
    public function getPassword(): string
    {
        return $this->hash;
    }
    public function eraseCredentials(): void {}
}
