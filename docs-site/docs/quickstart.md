---
title: Quickstart
description: From composer require to a working directory login in five steps — configure the JIT policy and group map, authenticate, and branch on the typed outcome.
---

# Quickstart

This page takes you from zero to a working directory login. It assumes a running
[Laravel IAM server](https://doc.laravel-iam-server.padosoft.com) and PHP **8.3+** on Laravel **13**. The
real LDAP transport is optional — see step 2.

## 1. Install

```bash
composer require padosoft/laravel-iam-directory
php artisan vendor:publish --tag=iam-directory-config
```

The `IamDirectoryServiceProvider` registers three singletons — `GroupMapper`, `DirectoryProvisioner` and
`DirectoryAuthenticator` — and publishes `config/iam-directory.php`.

## 2. Choose a transport

::: tabs
== tab "LDAP / Active Directory"
The built-in connector needs PHP's `ext-ldap` and LdapRecord:

```bash
composer require directorytree/ldaprecord-laravel
```

Then bind `Ldap\LdapConnector` as your `DirectoryConnector` (see [LDAP setup](/operations/ldap-setup)).
== tab "Custom source (no ext-ldap)"
No LDAP? Implement `DirectoryConnector` against any source and bind it — the entire hardened JIT/sync path
is reused unchanged (see [Custom connector](/guides/custom-connector)).
:::

::: callout warning "The connector is not wired by default"
The service provider does **not** bind a `DirectoryConnector` — it's an optional dependency. You must bind
one (LDAP or custom) or `DirectoryAuthenticator` cannot be resolved.
:::

## 3. Configure the JIT policy and group map

`config/iam-directory.php`:

```php
return [
    // Target organization for provisioning (null = global users, no membership, no grants).
    'organization_id' => env('IAM_DIRECTORY_ORG'),

    'jit' => [
        'require_verified_email' => true,
        'allowed_domains'        => ['acme.com'],          // [] = no domain restriction
        'approval_required'      => false,
        'default_roles'          => ['iam:tenant_member'], // bootstrap roles (full_key)
        'group_mapping'          => true,
        'protected_roles'        => ['iam:super_admin'],   // never grantable via the directory
    ],

    // Directory group → IAM role(s). Key = full DN or short CN (case-insensitive).
    'group_map' => [
        'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        'developers' => ['app:developer', 'app:deployer'],
    ],
];
```

::: callout danger "Set protected_roles before you map anything"
List every high-privilege role here. A single wrong row in `group_map` — or a compromised directory — must
never escalate a user to admin. `protected_roles` is the guardrail that makes that impossible.
:::

## 4. Authenticate a user

```php
use Padosoft\Iam\Directory\DirectoryAuthenticator;

$outcome = app(DirectoryAuthenticator::class)->login($username, $password);

match ($outcome->status) {
    'provisioned', 'linked' => Auth::loginUsingId($outcome->userId), // roles already synced
    'pending'  => back()->withErrors("Access pending: {$outcome->reason}"),
    'conflict' => back()->withErrors('That email belongs to an existing account — manual link required.'),
    'denied'   => back()->withErrors('Invalid credentials.'),
};
```

`login()` runs *authenticate → map groups → provision/sync* in one call and returns a typed
[`DirectoryOutcome`](/reference/outcomes).

## 5. Understand the outcomes

| `status` | When | `userId` | What happened |
|---|---|---|---|
| `provisioned` | First login, no existing user | set | New IAM user + membership + roles created in a transaction |
| `linked` | Existing **directory-sourced** user | set | Same user reused, roles re-synced to current groups |
| `pending` | JIT policy blocked it | `null` | `reason`: unverified email, disallowed domain, or approval needed |
| `conflict` | Email owned by a **non-directory** account | `null` | No takeover — a verified manual link is required |
| `denied` | Invalid credentials / connector error | `null` | Nothing in IAM was touched |

## What just happened

::: steps
1. **Authenticate** — the connector verified the credentials and returned a normalized `DirectoryUser`
   (or `null` → `denied`).
2. **Map** — `GroupMapper` translated the user's directory groups into IAM roles (`full_key`), case-insensitively.
3. **Gate** — `DirectoryJitPolicy` checked verified email, domain allowlist and approval.
4. **Provision / sync** — `DirectoryProvisioner` created or reused the user and made the directory-sourced
   grants match the mapped roles (adding missing, revoking stale), minus any `protected_roles`.
:::

## Next steps

- [Core concepts](/concepts) — the mental model behind every class.
- [Directory login](/guides/directory-login) — the login flow end to end.
- [Group → role mapping](/guides/group-mapping) — DN vs CN, lists, default-deny.
- [Anti-takeover](/security/anti-takeover) · [Authoritative sync](/security/authoritative-sync) ·
  [Protected roles](/security/protected-roles) — the security model.
