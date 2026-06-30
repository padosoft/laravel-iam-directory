---
title: Config keys
description: Every key in config/iam-directory.php — type, default, and meaning — for organization scope, the JIT policy, and the group map.
---

# Config keys

The published file is `config/iam-directory.php` (`php artisan vendor:publish --tag=iam-directory-config`).
This is the terse reference; the [Configuration guide](/operations/configuration) has recipes and rationale.

## Top level

| Key | Type | Default | Meaning |
|---|---|---|---|
| `organization_id` | `?string` | `env('IAM_DIRECTORY_ORG')` | Target org for provisioning. `null` = global users — **no membership, no grants**. |
| `jit` | `array` | see below | The Just-In-Time provisioning policy. |
| `group_map` | `array` | `[]` | Directory group → IAM role(s) mapping. |

## `jit` — the provisioning policy

Mapped to `DirectoryJitPolicy::fromArray()`. Evaluated in order before any write; a failure returns
`pending(reason)`.

| Key | Type | Default | Meaning | Failure reason |
|---|---|---|---|---|
| `require_verified_email` | `bool` | `true` | Require `DirectoryUser::emailVerified` before provisioning. | `jit_requires_verified_email` |
| `allowed_domains` | `list<string>` | `[]` | Email-domain allowlist (exact, lowercased). `[]` = no restriction. | `jit_domain_not_allowed` |
| `approval_required` | `bool` | `false` | Hold every provisioning for manual approval. | `jit_approval_required` |
| `default_roles` | `list<string>` | `[]` | Bootstrap roles (`full_key`) granted to every provisioned user. **Not** filtered by `protected_roles`. | — |
| `group_mapping` | `bool` | `true` | Whether to apply `group_map`. `false` = only `default_roles`. | — |
| `protected_roles` | `list<string>` | `[]` | Roles **never** grantable via the directory (subtracted from mapped roles). | — |

::: callout tip "fromArray is defensive"
Non-array or wrong-typed values fall back to the secure defaults; list fields keep only strings. A malformed
config can't silently produce a permissive policy.
:::

## `group_map` — group → role mapping

```php
'group_map' => [
    'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
    'developers' => ['app:developer', 'app:deployer'],
],
```

| Aspect | Rule |
|---|---|
| **Key** | Group **full DN** or **short CN**. Matched case-insensitively (lowercased + trimmed). |
| **CN extraction** | From a DN, the leftmost `cn=…` is also tried (`^cn=([^,]+)`). |
| **Value** | A single role `full_key`, or a `list<full_key>`. |
| **Normalization** | Empty strings and non-string entries are dropped. |
| **Unmapped groups** | Ignored — **default-deny**, no implicit roles. |
| **Output** | `rolesFor()` returns unique, **sorted** roles. |

See [Group → role mapping](/guides/group-mapping) for the full semantics.

## Environment variables

| Var | Key |
|---|---|
| `IAM_DIRECTORY_ORG` | `organization_id` |

LdapRecord connection settings (host, base DN, bind credentials, TLS) are **not** in this file — they're
configured through LdapRecord itself. See [LDAP setup](/operations/ldap-setup).

## Full annotated example

```php
return [
    // Provisioning scope. null = global users with no membership/grants.
    'organization_id' => env('IAM_DIRECTORY_ORG'),

    'jit' => [
        'require_verified_email' => true,                  // gate unverified emails
        'allowed_domains'        => ['acme.com'],          // [] = any domain
        'approval_required'      => false,                 // true = hold at 'pending'
        'default_roles'          => ['iam:tenant_member'], // every user; not protected-filtered
        'group_mapping'          => true,                  // apply group_map below
        'protected_roles'        => ['iam:super_admin'],   // never via the directory
    ],

    'group_map' => [
        'cn=warehouse-admins,ou=groups,dc=acme,dc=com' => 'warehouse:admin',
        'developers' => ['app:developer', 'app:deployer'],
    ],
];
```

## Related

- [Configuration guide](/operations/configuration) — recipes and rationale.
- [PHP API](/reference/php-api) — the `DirectoryJitPolicy` / `GroupMapper` signatures these keys feed.
