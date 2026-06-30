---
title: PHP API
description: Every class, signature and contract shipped by laravel-iam-directory, under Padosoft\Iam\Directory.
---

# PHP API

All classes live under `Padosoft\Iam\Directory\`. The four DTOs/policy are `final readonly`; the services are
`final`.

## Contracts\DirectoryConnector

The transport seam. Fail-closed: `null` means denied / not found, never an exception.

```php
namespace Padosoft\Iam\Directory\Contracts;

interface DirectoryConnector
{
    public function authenticate(string $username, string $password): ?DirectoryUser;
    public function find(string $username): ?DirectoryUser;   // no-credentials lookup (sync/admin)
}
```

See [Custom connector](/guides/custom-connector) and [Fail-closed transport](/security/fail-closed).

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
$mapper->rolesFor(array $groups): array;  // list<string> unique, sorted IAM roles; unmapped groups ignored
```

DN and CN are matched case-insensitively; the CN is extracted from a DN via `^cn=([^,]+)`. Default-deny: no
mapping → no role. See [Group → role mapping](/guides/group-mapping).

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

`fromArray()` is defensive: unknown/invalid values fall back to the secure defaults, and the list fields keep
only strings.

## DirectoryProvisioner

```php
$provisioner->provision(
    DirectoryUser $user,
    DirectoryJitPolicy $policy,
    ?string $organizationId,
    array $mappedRoles,             // list<string> from GroupMapper
): DirectoryOutcome;
```

Behaviour:

- evaluates the policy gate first (`pending` on failure — no writes);
- computes `effective = (default ∪ mapped) − protected`;
- **no existing user** → creates `User` in a `DB::transaction`, syncs grants → `provisioned`;
- **existing directory-sourced user** → reuses, syncs grants → `linked`;
- **existing non-directory user** → `conflict('email_taken_non_directory')`, no writes;
- sync is authoritative (adds missing, revokes stale `source=directory` grants, preserves manual grants) and
  idempotent; it no-ops when `organizationId` is `null`.

The private `sync()` writes through the IAM server models `User`, `Membership`, `Grant` — see
[Data model & contract](/architecture/data-model).

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

`login()` short-circuits to `denied` when the connector returns `null`. `sync()` runs the same
map → policy → provision path for an already-resolved user. Reads `config['jit']` (policy) and
`config['organization_id']` (scope).

## DirectoryOutcome  *(final readonly)*

```php
$outcome->status;   // 'provisioned' | 'linked' | 'conflict' | 'pending' | 'denied'
$outcome->userId;   // ?string — set for provisioned/linked
$outcome->reason;   // ?string — set for pending/conflict/denied
$outcome->roles;    // list<string> — roles granted in this pass
$outcome->ok();     // bool — true iff status ∈ { provisioned, linked }

DirectoryOutcome::provisioned(string $userId, array $roles): self;
DirectoryOutcome::linked(string $userId, array $roles): self;
DirectoryOutcome::pending(string $reason): self;
DirectoryOutcome::conflict(string $reason): self;   // email taken by a non-directory account
DirectoryOutcome::denied(): self;                   // reason = 'invalid_credentials'
```

See [Outcomes & reasons](/reference/outcomes) for every reason string.

## Ldap\LdapConnector  *(optional)*

The real LDAP/AD transport via LdapRecord. Requires `ext-ldap` + `directorytree/ldaprecord-laravel`
(a `suggest`). Isolated under `src/Ldap/` and excluded from PHPStan so the core stays LDAP-free.

```php
new LdapConnector(
    LdapRecord\Connection $connection,
    string $model,                          // class-string<LdapRecord\Models\Model>
    string $usernameAttribute = 'samaccountname',
);
```

Maps `mail`→email, `cn`→displayName, `memberof`→groups (DNs); a present `mail` ⇒ `emailVerified = true`. Every
failure (unknown user, empty password, rejected/erroring bind) → `null`. See [LDAP setup](/operations/ldap-setup).

## IamDirectoryServiceProvider

Registers `GroupMapper`, `DirectoryProvisioner` and `DirectoryAuthenticator` as singletons and publishes the
`iam-directory` config. Does **not** bind `DirectoryConnector` — you must. See
[Installation](/installation#what-the-service-provider-registers).

## Related

- [Config keys](/reference/config) — the config contract.
- [Outcomes & reasons](/reference/outcomes) — the result vocabulary.
- [Core concepts](/concepts) — how these classes fit together.
