<?php

declare(strict_types=1);

namespace Padosoft\Iam\Directory;

/**
 * Policy JIT della directory (doc 10 §6), secure-by-default come la federazione: serve email
 * verificata, opzionale allowlist di domini, opzionale approval. `defaultRoles` sono i ruoli
 * bootstrap; `groupMapping` decide se applicare anche la mappa gruppi→ruoli.
 */
final readonly class DirectoryJitPolicy
{
    /**
     * @param  list<string>  $allowedDomains
     * @param  list<string>  $defaultRoles
     * @param  list<string>  $protectedRoles  ruoli mai concedibili via group mapping (solo-manuale)
     */
    public function __construct(
        public bool $requireVerifiedEmail = true,
        public array $allowedDomains = [],
        public bool $approvalRequired = false,
        public array $defaultRoles = [],
        public bool $groupMapping = true,
        public array $protectedRoles = [],
    ) {}

    /** @param array<array-key, mixed> $p */
    public static function fromArray(array $p): self
    {
        return new self(
            requireVerifiedEmail: ($p['require_verified_email'] ?? true) !== false,
            allowedDomains: self::stringList($p['allowed_domains'] ?? []),
            approvalRequired: ($p['approval_required'] ?? false) === true,
            defaultRoles: self::stringList($p['default_roles'] ?? []),
            groupMapping: ($p['group_mapping'] ?? true) !== false,
            protectedRoles: self::stringList($p['protected_roles'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
