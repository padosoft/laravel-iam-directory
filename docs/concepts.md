---
title: Concepts
description: The mental model behind the Directory module — connector, normalized user, group mapper, JIT policy, provisioner, outcome.
---

# Concepts

## The problem

Your identities live in LDAP/Active Directory, but your authorization lives in Laravel IAM. You need to let
directory users log in, create their IAM accounts on demand, and keep their roles aligned with their
directory groups — **without** opening the three holes that naive sync code opens: account takeover, stale
privileges, and group-mapping escalation.

## Mental model

The module is a small pipeline, decoupled from the transport:

```
credentials ─▶ DirectoryConnector ─▶ DirectoryUser ─▶ GroupMapper ─▶ roles
                                                          │
                                   DirectoryJitPolicy ────┤
                                                          ▼
                                              DirectoryProvisioner ─▶ DirectoryOutcome
```

Everything downstream of `DirectoryConnector` works on a **normalized `DirectoryUser`** — so the core never
depends on LDAP types and is trivially testable.

## Core entities

::: card "DirectoryConnector"
The transport seam. `authenticate(user, pass): ?DirectoryUser` and `find(user): ?DirectoryUser`. Returns
`null` (= denied / not found) on failure — **never** an opaque exception. The LDAP/AD implementation
(`Ldap\LdapConnector`) is optional; any custom source can implement it.
:::

::: card "DirectoryUser"
A `final readonly` normalized identity: `username`, `email`, `emailVerified`, `displayName`, and `groups`
(DN **or** short CN). Helpers `normalizedEmail()` / `emailDomain()`.
:::

::: card "GroupMapper"
Translates the user's `groups` into IAM roles (`full_key`), matching DN and CN case-insensitively. Unmapped
groups are ignored — **default-deny**, no implicit roles.
:::

::: card "DirectoryJitPolicy"
Secure-by-default policy: `requireVerifiedEmail`, `allowedDomains`, `approvalRequired`, `defaultRoles`,
`groupMapping`, and `protectedRoles` (roles the directory may never grant).
:::

::: card "DirectoryProvisioner"
Creates the user/membership/grants on first login and **re-syncs authoritatively** afterwards: adds missing
roles, revokes stale directory-sourced ones, leaves manual grants alone.
:::

::: card "DirectoryOutcome"
The typed result: `provisioned` · `linked` · `conflict` · `pending` · `denied`, plus the `roles` granted in
this pass.
:::

## Example

```php
$user = new DirectoryUser('jdoe', 'jdoe@acme.com', emailVerified: true, groups: ['developers']);
$roles = (new GroupMapper(['developers' => ['app:developer']]))->rolesFor($user->groups);
// → ['app:developer']
$outcome = $provisioner->provision($user, $policy, 'org_123', $roles);
// → DirectoryOutcome::provisioned('user_…', ['app:developer'])
```

## Anti-patterns

::: callout danger "Don't auto-link by email"
Linking any account whose email matches the directory entry is an **account-takeover vector** — an attacker
who can set an email in LDAP inherits a local account. This module only reuses accounts already
`source=directory`; anything else is a `conflict` requiring a manual, verified link.
:::

::: callout danger "Don't leave roles behind"
If you only ever *add* roles, a user removed from an LDAP group keeps the privilege forever. The sync is
**authoritative**: it revokes directory-sourced roles no longer mapped.
:::

::: callout danger "Don't trust group_map blindly"
A wrong or compromised `group_map` row must not be able to grant `iam:super_admin`. `protected_roles` are
filtered out of mapped roles — always manual-only.
:::

## Why this design

Decoupling the core from LDAP (via `DirectoryConnector` + `DirectoryUser`) keeps the package installable and
analyzable without `ext-ldap`, makes provisioning logic unit-testable, and lets non-LDAP sources reuse the
exact same hardened JIT/sync path. The security invariants live in one place — the provisioner — not
scattered across every call site.
