<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

/**
 * Identità normalizzata restituita da una directory (doc 10 §6). Disaccoppia il core (group
 * mapping + JIT) dal trasporto: un `DirectoryConnector` LDAP/AD la popola dagli attributi LDAP, ma
 * un connettore custom può produrla da qualunque fonte. `groups` sono DN o nomi corti dei gruppi.
 */
final readonly class DirectoryUser
{
    /** @param list<string> $groups DN completi o nomi corti dei gruppi di appartenenza */
    public function __construct(
        public string $username,
        public ?string $email = null,
        public bool $emailVerified = false,
        public ?string $displayName = null,
        public array $groups = [],
    ) {}

    /** Email normalizzata (lowercase, trim) o null. */
    public function normalizedEmail(): ?string
    {
        if ($this->email === null) {
            return null;
        }
        $email = strtolower(trim($this->email));

        return $email !== '' ? $email : null;
    }

    public function emailDomain(): ?string
    {
        $email = $this->normalizedEmail();
        if ($email === null) {
            return null;
        }
        $at = strrpos($email, '@');

        return $at !== false ? substr($email, $at + 1) : null;
    }
}
