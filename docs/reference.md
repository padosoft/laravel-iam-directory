---
title: Reference
description: Every class, signature and config key shipped by laravel-iam-directory.
---

# Reference

All classes live under `Padosoft\Iam\Directory\`.

## Contracts\DirectoryConnector

The transport seam. Fail-closed: `null` means denied / not found, never an exception.

```php
interface DirectoryConnector
{
    public function authenticate(string $username, string $password): ?DirectoryUser;
    public function find(string $username): ?DirectoryUser;   // no-credentials lookup (sync/admin)
}
```

## DirectoryUser  *(final readonly)*

Normalized identity, decoupled from the transport.

```php
new DirectoryUser(
    string $username,
    ?string $email = null,
    bool $emailVerified = false,
    ?string $displayName = null,
    array $groups = [],            // list<string>: full DNs or short CNs
);

$user->normalizedEmail(): ?string; // lowercase + trim, or null
$user->emailDomain(): ?string;     // domain part of the normalized email, or null
```

## GroupMapper

```php
new GroupMapper(array $map);              // [DN-or-CN => full_key | list<full_key>]
$mapper->rolesFor(array $groups): array;  // list<string> unique IAM roles; unmapped groups ignored
```

DN and CN are matched case-insensitively. Default-deny: no mapping → no role.

## DirectoryJitPolicy  *(final readonly)*

```php
new DirectoryJitPolicy(
    bool $requireVerifiedEmail = true,
    array $allowedDomains = [],     // list<string>; [] = no restriction
    bool $approvalRequired = false,
    array $defaultRoles = [],       // list<string> full_key (bootstrap)
    bool $groupMapping = true,
    array $protectedRoles = [],     // list<string> never grantable via the directory
);

DirectoryJitPolicy::fromArray(array $config): self;   // maps the `jit` config section
```

## DirectoryProvisioner

```php
$provisioner->provision(
    DirectoryUser $user,
    DirectoryJitPolicy $policy,
    ?string $organizationId,
    array $mappedRoles,             // list<string> from GroupMapper
): DirectoryOutcome;
```

Creates user + membership + grants on first login (in a DB transaction); re-syncs authoritatively
afterwards (adds missing, revokes stale `source=directory` grants, preserves manual grants).

## DirectoryAuthenticator

```php
new DirectoryAuthenticator(
    DirectoryConnector $connector,
    GroupMapper $mapper,
    DirectoryProvisioner $provisioner,
    array $config = [],             // the `iam-directory` config section
);

$auth->login(string $username, string $password): DirectoryOutcome;  // authenticate → map → provision
$auth->sync(DirectoryUser $user): DirectoryOutcome;                  // admin sync, no credentials
```

## DirectoryOutcome  *(final readonly)*

```php
$outcome->status;   // 'provisioned' | 'linked' | 'conflict' | 'pending' | 'denied'
$outcome->userId;   // ?string — set for provisioned/linked
$outcome->reason;   // ?string — set for pending/conflict
$outcome->roles;    // list<string> — roles granted in this pass

DirectoryOutcome::provisioned(string $userId, array $roles): self;
DirectoryOutcome::linked(string $userId, array $roles): self;
DirectoryOutcome::pending(string $reason): self;
DirectoryOutcome::conflict(string $reason): self;   // email taken by a non-directory account
DirectoryOutcome::denied(): self;                   // invalid credentials
```

## Ldap\LdapConnector  *(optional)*

The real LDAP/AD transport via LdapRecord. Requires `ext-ldap` + `directorytree/ldaprecord-laravel`
(a `suggest`). Isolated under `src/Ldap/` and excluded from PHPStan so the core stays LDAP-free.

## config/iam-directory.php

| Key | Meaning |
| --- | --- |
| `organization_id` | Target org for provisioning; `null` = global users (no membership, no grants) |
| `jit.require_verified_email` | Require a verified email before provisioning (default `true`) |
| `jit.allowed_domains` | Email-domain allowlist; `[]` = no restriction |
| `jit.approval_required` | Hold provisioning for manual approval (`pending`) |
| `jit.default_roles` | Bootstrap roles (`full_key`) granted to every provisioned user |
| `jit.group_mapping` | Whether to apply the group → role map |
| `jit.protected_roles` | Roles never grantable via the directory (manual-only) |
| `group_map` | `DN-or-CN => full_key | list<full_key>` (case-insensitive) |
