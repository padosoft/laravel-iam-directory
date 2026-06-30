---
title: ADR — decisions
description: The architectural decision records behind the Directory module — LDAP isolation, the normalized DirectoryUser seam, authoritative sync, anti-takeover, protected roles, and fail-closed.
---

# ADR — architectural decisions

Each record follows *Problem → Decision → Consequences*. Together they explain why the module is built the
way it is — and which invariants you must not regress.

## ADR-001 — Isolate LDAP behind an optional transport

::: collapsible "Problem → Decision → Consequences" open
**Problem.** The security-critical logic must be installable and analysable everywhere, but LDAP/`ext-ldap`
is awkward in CI and Herd and can't be unit-tested without a live server.

**Decision.** Define a `DirectoryConnector` interface returning a normalized `DirectoryUser`. Implement the
real LDAP/AD transport in a single class, `Ldap\LdapConnector`, under `src/Ldap/`; exclude it from PHPStan;
put `directorytree/ldaprecord-laravel` in `suggest`, not `require`.

**Consequences.** The core installs and passes static analysis without `ext-ldap`; provisioning is
unit-tested against a fake connector; non-LDAP sources reuse the same path. Cost: one abstraction, and the
connector must be bound explicitly by the app.
:::

## ADR-002 — A normalized DirectoryUser seam

::: collapsible "Problem → Decision → Consequences"
**Problem.** If the core consumed LDAP entries directly, every downstream class would couple to LdapRecord
types and to AD-specific attribute shapes.

**Decision.** Connectors return a `final readonly DirectoryUser` with just `username`, `email`,
`emailVerified`, `displayName`, `groups`. All identity normalization (lowercasing email, extracting domain)
lives on this DTO.

**Consequences.** The mapper, policy and provisioner are pure and trivially testable. Adding a new source
means producing this DTO — nothing downstream changes. Cost: connectors must map their native attributes
onto it (`mail`→email, `cn`→displayName, `memberOf`→groups for LDAP).
:::

## ADR-003 — Authoritative sync, not append-only

::: collapsible "Problem → Decision → Consequences"
**Problem.** Append-only role sync lets privileges persist after a user leaves a group — a least-privilege
violation and a drift from the directory as source of truth.

**Decision.** `sync()` makes the active **directory-sourced** grants *equal* the currently wanted roles:
revoke those no longer mapped (`directory_sync_removed`), add those missing, and never touch grants with
`source ≠ directory`.

**Consequences.** Leaving a group revokes the role on next login; manual grants are preserved; the operation
is idempotent. Cost: roles are scoped to a membership, so a `null` organization grants/revokes nothing.
See [Authoritative sync](/security/authoritative-sync).
:::

## ADR-004 — Anti-takeover on email collision

::: collapsible "Problem → Decision → Consequences"
**Problem.** Auto-linking any account whose email matches a directory entry lets anyone who can set `mail` in
the directory seize an existing local account.

**Decision.** Reuse an existing account **only** if it's already directory-sourced
(`Membership.source = directory`, via `isDirectorySourced()`). Any other collision returns
`conflict('email_taken_non_directory')` and writes nothing; a human performs a verified manual link.

**Consequences.** No takeover via LDAP. Cost: legitimate "this directory user is that local user" cases need
an explicit, human-driven link step. See [Anti-takeover](/security/anti-takeover).
:::

## ADR-005 — Protected roles are never directory-grantable

::: collapsible "Problem → Decision → Consequences"
**Problem.** A single wrong or compromised `group_map` row could map a group to `iam:super_admin` and
escalate everyone in it.

**Decision.** `rolesToGrant()` subtracts `protected_roles` from the **mapped** set:
`effective = default ∪ (mapped − protected)`. `default_roles` are exempt — they're an explicit operator
choice, not directory-driven.

**Consequences.** No `group_map` edit can grant a protected role; those roles stay manual-only. Cost: you
must remember a sensitive role placed in `default_roles` bypasses the filter by design. See
[Protected roles](/security/protected-roles).
:::

## ADR-006 — Fail-closed everywhere

::: collapsible "Problem → Decision → Consequences"
**Problem.** Auth code that throws on transport errors invites a caller `catch` that fails *open*.

**Decision.** `DirectoryConnector::authenticate()` returns `null` on invalid credentials **and** any error;
the orchestrator maps `null` to `denied`. The LDAP connector catches every bind/transport exception and
returns `null`. The policy gate runs before any write (`pending`, no partial provisioning); user creation is
transactional. Only `provisioned`/`linked` are "allow" (`ok()`).

**Consequences.** No failure path admits a user. Cost: a transient directory outage denies a legitimate login
— the safe direction. See [Fail-closed transport](/security/fail-closed).
:::

## ADR-007 — The directory grants roles; the PDP decides allow/deny

::: collapsible "Problem → Decision → Consequences"
**Problem.** Conflating "which roles a user has" with "what they may do" would duplicate authorization logic
in this module.

**Decision.** The module's sole authorization output is **role grants** on the IAM server. The runtime
allow/deny decision is the [PDP's](https://doc.laravel-iam-server.padosoft.com) responsibility, against the
application manifests.

**Consequences.** This module stays small and single-purpose; authorization semantics live in one place (the
server). Cost: roles granted here are only meaningful once the server's manifests define what they can do.
:::

## Roadmap

::: callout info "v2 — SCIM" icon:map
v1 ships LDAP/Active Directory via LdapRecord plus the LDAP-free core. **SCIM** (System for Cross-domain
Identity Management) provisioning is planned for v2, and will slot in as another `DirectoryConnector` /
provisioning source behind the same hardened invariants documented here.
:::

## Related

- [Architecture overview](/architecture/overview) — the wiring these decisions produce.
- [Core concepts](/concepts) — the entities the ADRs reference.
