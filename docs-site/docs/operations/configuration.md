---
title: Configuration
description: Every knob in config/iam-directory.php — organization scope, the JIT policy, group mapping — with safe defaults and worked recipes.
---

# Configuration

All configuration lives in `config/iam-directory.php`, published with:

```bash
php artisan vendor:publish --tag=iam-directory-config
```

This page is the operational guide; the [Config reference](/reference/config) is the terse table.

## The published default

```php
return [
    'organization_id' => env('IAM_DIRECTORY_ORG'),

    'jit' => [
        'require_verified_email' => true,
        'allowed_domains' => [],        // [] = no domain restriction
        'approval_required' => false,
        'default_roles' => [],          // bootstrap roles, full_key
        'group_mapping' => true,
        'protected_roles' => [],        // never grantable via the directory
    ],

    'group_map' => [
        // 'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        // 'developers' => ['app:developer', 'app:deployer'],
    ],
];
```

The defaults are secure: verified email required, no implicit roles, group mapping on but with an empty map
(so nothing is granted until you fill it).

## Organization scope

```php
'organization_id' => env('IAM_DIRECTORY_ORG'),   // string id, or null
```

- **A string org id** → users are provisioned into that organization; a `Membership` (`source = directory`) is
  ensured and role grants are scoped to it.
- **`null`** → users are *global*: the identity is created/identified, but **no membership and no grants** are
  written. `roles` in the outcome will be empty.

::: callout warning "No org means no roles"
This is the single most common "roles aren't granted" cause. If you want directory groups to grant IAM roles,
you must set `organization_id`. See [Authoritative sync](/security/authoritative-sync#scope-roles-live-under-a-membership).
:::

## The JIT policy

Evaluated in order before any write; a failure returns `pending(reason)`.

::: tabs
== tab "require_verified_email"
```php
'require_verified_email' => true,   // default
```
If on, a `DirectoryUser` with `emailVerified = false` → `pending: jit_requires_verified_email`. The built-in
LDAP connector treats a present `mail` as verified; a custom connector controls this flag itself.
== tab "allowed_domains"
```php
'allowed_domains' => ['acme.com', 'acme.co.uk'],   // [] = no restriction
```
If non-empty, the email's domain must be in the list, else `pending: jit_domain_not_allowed`. Matching is
exact (lowercased) on the substring after the last `@`.
== tab "approval_required"
```php
'approval_required' => false,
```
If on, every provisioning is held: `pending: jit_approval_required`. Approve and complete the link out-of-band
(e.g. an admin runs `sync()` for the resolved user once approved).
== tab "default_roles"
```php
'default_roles' => ['iam:tenant_member'],   // full_key
```
Bootstrap roles granted to every provisioned user, unioned with mapped roles. **Not** filtered by
`protected_roles` — an explicit operator choice.
== tab "group_mapping"
```php
'group_mapping' => true,
```
Master switch for the `group_map`. With it `false`, only `default_roles` are granted — the directory groups
are ignored entirely.
== tab "protected_roles"
```php
'protected_roles' => ['iam:super_admin', 'billing:owner'],   // full_key
```
Roles **subtracted** from the mapped set — never grantable via the directory. See
[Protected roles](/security/protected-roles).
:::

## The group map

```php
'group_map' => [
    'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',   // full DN → single role
    'developers' => ['app:developer', 'app:deployer'],                     // short CN → list
],
```

Keys are group **DNs or CNs** (case-insensitive); values are role `full_key`s, single or list. Unmapped
groups grant nothing (default-deny). Details and CN-extraction rules in
[Group → role mapping](/guides/group-mapping).

## Recipes

::: collapsible "Recipe — strict enterprise tenant" open
```php
'organization_id' => env('IAM_DIRECTORY_ORG'),   // a real org id
'jit' => [
    'require_verified_email' => true,
    'allowed_domains'        => ['acme.com'],
    'approval_required'      => false,
    'default_roles'          => ['iam:tenant_member'],
    'group_mapping'          => true,
    'protected_roles'        => ['iam:super_admin'],
],
```
Every directory user gets `iam:tenant_member` plus whatever their groups map to, scoped to the org, with
super-admin impossible to obtain via the directory.
:::

::: collapsible "Recipe — approval-gated onboarding"
```php
'jit' => [
    'require_verified_email' => true,
    'approval_required'      => true,    // hold everyone at 'pending'
    'default_roles'          => [],
    'group_mapping'          => true,
    'protected_roles'        => ['iam:super_admin'],
],
```
First logins return `pending: jit_approval_required`. An admin reviews, then calls `sync()` for the resolved
user (with `approval_required` relaxed for that path, or after flipping the user to approved upstream).
:::

::: collapsible "Recipe — default roles only (no group mapping)"
```php
'jit' => [
    'default_roles'  => ['iam:tenant_member'],
    'group_mapping'  => false,            // ignore directory groups entirely
],
'group_map' => [],
```
Useful when the directory is an authentication source only, and roles are managed manually in IAM.
:::

## Environment variables

| Var | Maps to |
|---|---|
| `IAM_DIRECTORY_ORG` | `organization_id` |

LdapRecord's own connection settings (host, base DN, bind user) are configured through LdapRecord's config,
not this file — see [LDAP setup](/operations/ldap-setup).

## Related

- [Config keys](/reference/config) — the terse reference table.
- [Troubleshooting](/operations/troubleshooting) — symptoms mapped to config causes.
