---
title: Custom (non-LDAP) connector
description: Implement DirectoryConnector against any source — CSV, HR API, legacy DB — and reuse the exact same hardened JIT/sync path with no ext-ldap.
---

# Custom (non-LDAP) connector

## Motivation

Not every "directory" is LDAP. Your authoritative source of users might be an HR system's REST API, a
provisioning CSV, a SCIM endpoint (until native SCIM lands in v2), or a legacy database. The module's core
doesn't care: it works on a normalized `DirectoryUser` produced by a `DirectoryConnector`, so **any** source
that can verify a credential and list a user's groups plugs into the same hardened JIT/sync path — with no
`ext-ldap`.

## The contract

```php
namespace Padosoft\Iam\Directory\Contracts;

use Padosoft\Iam\Directory\DirectoryUser;

interface DirectoryConnector
{
    public function authenticate(string $username, string $password): ?DirectoryUser;
    public function find(string $username): ?DirectoryUser;   // no-credentials lookup
}
```

Two methods, two rules:

::: callout danger "Fail-closed: return null, never throw an opaque error"
`authenticate()` returns `null` for invalid credentials **and** for any transport error. The orchestrator
turns `null` into `DirectoryOutcome::denied()`. If you let an exception escape, you risk a caller `catch`
that fails *open* — exactly the bug this design exists to prevent. Catch your own errors and return `null`.
:::

`find()` is a no-credentials lookup used by administrative `sync()` (and your own enumeration jobs). It
returns the same normalized `DirectoryUser`, or `null` if the user doesn't exist.

## Building a DirectoryUser

```php
use Padosoft\Iam\Directory\DirectoryUser;

new DirectoryUser(
    username: 'jdoe',
    email: 'jdoe@acme.com',
    emailVerified: true,                 // gate: require_verified_email
    displayName: 'Jane Doe',
    groups: ['developers', 'oncall'],    // DNs or short CNs — your choice
);
```

- **`email`** drives identity matching and the domain allowlist. `normalizedEmail()` lowercases and trims it.
- **`emailVerified`** must be `true` to pass `require_verified_email`. Set it only when your source genuinely
  verifies the address.
- **`groups`** are fed to `GroupMapper`. Emit whatever form your `group_map` keys use — DNs or CNs both work
  (see [Group → role mapping](/guides/group-mapping)).

## A worked example — an HR API connector

::: tabs
== tab "The connector"
```php
use Padosoft\Iam\Directory\Contracts\DirectoryConnector;
use Padosoft\Iam\Directory\DirectoryUser;

final class HrApiConnector implements DirectoryConnector
{
    public function __construct(private readonly HrClient $api) {}

    public function authenticate(string $username, string $password): ?DirectoryUser
    {
        try {
            $row = $this->api->verifyCredentials($username, $password);
        } catch (\Throwable) {
            return null;                          // transport error → denied (fail-closed)
        }

        return $row === null ? null : $this->toUser($row);
    }

    public function find(string $username): ?DirectoryUser
    {
        $row = $this->api->lookup($username);     // no credentials

        return $row === null ? null : $this->toUser($row);
    }

    private function toUser(array $row): DirectoryUser
    {
        return new DirectoryUser(
            username: $row['uid'],
            email: $row['mail'] ?? null,
            emailVerified: (bool) ($row['mail_verified'] ?? false),
            displayName: $row['display_name'] ?? null,
            groups: $row['groups'] ?? [],          // CNs from your HR system
        );
    }
}
```
== tab "Bind it"
```php
// AppServiceProvider::register()
$this->app->bind(
    \Padosoft\Iam\Directory\Contracts\DirectoryConnector::class,
    \App\Directory\HrApiConnector::class,
);
```
== tab "Use it"
```php
// Exactly the same as LDAP — the core doesn't know the difference.
$outcome = app(\Padosoft\Iam\Directory\DirectoryAuthenticator::class)
    ->login($username, $password);
```
:::

## Why this is safe to do

The connector only *produces identity*. It never decides provisioning, linking, or which roles to grant —
those invariants live entirely in `DirectoryProvisioner`. A custom connector therefore **cannot** relax
anti-takeover, stale-role revocation, or `protected_roles`: feeding the provisioner a `DirectoryUser` runs it
through the same gate as LDAP.

::: callout warning "Don't fake verification or smuggle privileges"
Returning `emailVerified: true` for an address you didn't verify defeats the JIT gate. And `groups` are just
identity — they only become roles through *your* `group_map`, and `protected_roles` still can't be granted.
Keep the connector honest; the provisioner will keep the rest safe.
:::

## Testing without LDAP

Because the seam is a plain interface, a test connector is trivial — which is exactly how the package's own
provisioning tests run with no `ext-ldap`:

```php
final class FakeConnector implements DirectoryConnector
{
    public function __construct(private readonly ?DirectoryUser $user) {}
    public function authenticate(string $u, string $p): ?DirectoryUser => $this->user;
    public function find(string $u): ?DirectoryUser => $this->user;
}
```

## Related

- [Core concepts](/concepts) — why the core is decoupled from LDAP.
- [Fail-closed transport](/security/fail-closed) — the `null`-not-throw rule in depth.
- [LDAP / Active Directory setup](/operations/ldap-setup) — the built-in connector, when you do have LDAP.
