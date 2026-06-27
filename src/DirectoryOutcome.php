<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

/**
 * Esito di un accesso/sync directory. `provisioned` = nuovo utente creato; `linked` = utente
 * directory esistente ri-sincronizzato (ruoli aggiornati); `conflict` = un'email già appartiene a un
 * account NON-directory → niente takeover, serve link manuale; `pending` = JIT bloccato dalla policy
 * (email non verificata, dominio non ammesso, approval); `denied` = credenziali non valide. `roles`
 * sono i ruoli effettivamente garantiti in questo passaggio.
 */
final readonly class DirectoryOutcome
{
    /** @param list<string> $roles */
    private function __construct(
        public string $status,
        public ?string $userId = null,
        public ?string $reason = null,
        public array $roles = [],
    ) {}

    /** @param list<string> $roles */
    public static function provisioned(string $userId, array $roles): self
    {
        return new self('provisioned', $userId, null, $roles);
    }

    /** @param list<string> $roles */
    public static function linked(string $userId, array $roles): self
    {
        return new self('linked', $userId, null, $roles);
    }

    public static function pending(string $reason): self
    {
        return new self('pending', null, $reason);
    }

    /** Email già presa da un account non-directory: si rifiuta il takeover (serve link manuale). */
    public static function conflict(string $reason): self
    {
        return new self('conflict', null, $reason);
    }

    public static function denied(): self
    {
        return new self('denied', null, 'invalid_credentials');
    }

    public function ok(): bool
    {
        return $this->status === 'provisioned' || $this->status === 'linked';
    }
}
