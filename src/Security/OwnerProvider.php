<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<Owner> */
final class OwnerProvider implements UserProviderInterface
{
    public function __construct(private readonly Connection $db) {}
    public function exists(): bool
    {
        return (bool) $this->db->fetchOne('SELECT COUNT(*) FROM owner_auth');
    }
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $hash = $this->db->fetchOne('SELECT password_hash FROM owner_auth WHERE id = 1');
        if ($identifier !== 'owner' || !$hash) {
            throw new UserNotFoundException();
        }
        return new Owner($hash);
    }
    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }
    public function supportsClass(string $class): bool
    {
        return $class === Owner::class;
    }
}
