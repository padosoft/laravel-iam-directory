---
title: Troubleshooting
description: Symptoms mapped to causes — everyone pending, roles not granted, unexpected conflict, binding errors, duplicate grants — with the exact config or code to check.
---

# Troubleshooting

Each entry is *symptom → likely cause → fix*. The outcomes referenced are defined in
[Outcomes & reasons](/reference/outcomes).

## Everyone comes back `pending: jit_requires_verified_email`

**Cause.** The connector returns `emailVerified: false`. The built-in `Ldap\LdapConnector` only marks email
verified when the `mail` attribute is present — so users without `mail` populated fail this gate. A custom
connector sets the flag itself.

**Fix.** Populate `mail` in the directory, set `emailVerified: true` in your connector when you genuinely
verify the address, or — deliberately — set `'require_verified_email' => false`.

## Everyone comes back `pending: jit_domain_not_allowed`

**Cause.** `allowed_domains` is non-empty and the user's email domain isn't in it (exact, lowercased match on
the part after the last `@`).

**Fix.** Add the domain(s), or set `'allowed_domains' => []` to disable the restriction.

## Roles aren't granted (outcome is `provisioned`/`linked` but `roles` is empty)

::: callout warning "The number-one cause: organization_id is null"
Grants are scoped to a membership. With `organization_id => null`, the user is created/identified but **no**
membership and **no** grants are written. Set the target org.
:::

Other causes, in order of likelihood:

::: steps
1. **`organization_id` is `null`** — set it (see [Configuration](/operations/configuration#organization-scope)).
2. **`group_mapping` is `false`** — only `default_roles` are granted; flip it on to use `group_map`.
3. **The group key doesn't match** — the LDAP connector emits **DNs** from `memberof`; confirm your
   `group_map` key is the exact DN, or rely on the CN the mapper extracts. Check with
   `app(GroupMapper::class)->rolesFor([$theGroupString])`.
4. **The role is in `protected_roles`** — it's filtered out of mapped roles by design. Grant it manually.
:::

## A user unexpectedly gets `conflict`

**Cause.** Their email already belongs to an account that is **not** directory-sourced (a local signup, or an
account created by another flow) — `email_taken_non_directory`. This is [anti-takeover](/security/anti-takeover)
working as intended.

**Fix.** Don't auto-resolve it in code. Have an administrator verify the directory user really owns that local
account, then perform a deliberate manual link before the user signs in via the directory.

## A role I removed in the directory is still active

**Cause.** Sync only runs with an `organization_id`, and only revokes grants whose `source = directory`. If
the grant was assigned manually (`source ≠ directory`), it is **preserved** by design.

**Fix.** Confirm the org is set, and check the grant's `source`. Directory-sourced roles are revoked on the
next login after the group is removed (reason `directory_sync_removed`); manual grants must be revoked
manually.

## `denied` for credentials that look correct

**Cause (LDAP).** Any of: the username search attribute is wrong (`samaccountname` vs `uid`), the bind
DN/password for the service account is misconfigured, an empty password was submitted, or a transport/TLS
error — all collapse to `null → denied` ([fail-closed](/security/fail-closed)).

**Fix.** Verify LdapRecord's connection config and the `usernameAttribute` you passed to `LdapConnector`. Test
the bind with LdapRecord directly to separate a connection problem from a mapping problem. Remember: the
module intentionally hides the *reason* a bind failed from the caller — check LdapRecord/server logs.

## `DirectoryAuthenticator` throws a binding/resolution error

**Cause.** No `DirectoryConnector` is bound. The service provider does **not** bind one by default.

**Fix.** Bind your connector (LDAP or custom) in `AppServiceProvider` — see
[Installation](/installation#you-must-bind-a-directoryconnector).

## Duplicate grants after repeated logins

**Cause.** This shouldn't happen — `sync()` checks for an existing active grant before inserting, and is
idempotent. If you see duplicates, you likely have a second code path granting roles outside this module.

**Fix.** Confirm only `DirectoryProvisioner` writes directory-sourced grants. The package's own tests assert
idempotency; a custom fork must keep that test green.

## "Class not found: LdapRecord\\Connection"

**Cause.** You're binding `Ldap\LdapConnector` without `directorytree/ldaprecord-laravel` installed (it's a
`suggest`, and needs `ext-ldap`).

**Fix.** `composer require directorytree/ldaprecord-laravel` and ensure `ext-ldap` is enabled — or switch to a
[custom connector](/guides/custom-connector) that doesn't need LDAP.

## Related

- [Configuration](/operations/configuration) — every knob and its effect.
- [LDAP / Active Directory setup](/operations/ldap-setup) — connection and attribute mapping.
- [Outcomes & reasons](/reference/outcomes) — the meaning of every status and reason.
