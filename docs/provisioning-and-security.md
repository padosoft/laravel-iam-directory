---
title: Provisioning & security
description: The hardened JIT provisioning and sync model — anti-takeover, stale-role revocation, protected roles, secure-by-default policy.
---

# Provisioning & security

This is the heart of the module. The `DirectoryProvisioner` is the single place where every security
invariant lives, so they can't be bypassed by a call site.

## The JIT policy gate (secure-by-default)

Before anything is created, `DirectoryJitPolicy` is evaluated in order. Any failed check returns
`DirectoryOutcome::pending(reason)` — never a partial provisioning:

::: steps
1. **Verified email** — if `require_verified_email` and the user's email isn't verified → `pending:
   jit_requires_verified_email`.
2. **Domain allowlist** — if `allowed_domains` is set and the email's domain isn't in it → `pending:
   jit_domain_not_allowed`.
3. **Approval** — if `approval_required` → `pending: jit_approval_required` (a human approves out-of-band).
:::

## Anti-takeover (email collision)

When the email already exists in IAM, the provisioner does **not** blindly link it:

::: callout danger "Why this matters"
If the module linked any matching email, anyone able to set an email on a directory entry could inherit an
existing local account. That's account takeover via LDAP.
:::

The rule: **reuse only an account the directory already provisioned** (`Membership.source = directory`,
checked by `isDirectorySourced()`). Otherwise → `DirectoryOutcome::conflict('email_taken_non_directory')`,
and a human must perform a verified manual link.

| Existing account | Result |
| --- | --- |
| None | `provisioned` (new user created in a transaction) |
| `source = directory` | `linked` (reused, roles re-synced) |
| `source ≠ directory` (local/other) | `conflict` — **no takeover** |

## Authoritative sync (no privilege persistence)

On every login `sync()` makes the directory-sourced grants match the **current** mapped roles:

- adds roles that are mapped but missing (idempotent — no duplicate grants);
- **revokes** directory-sourced roles that are no longer mapped (e.g. the user left an LDAP group), with
  reason `directory_sync_removed`;
- **never touches** grants with `source ≠ directory` — manual assignments are preserved.

::: callout warning "Roles are scoped to a membership"
Sync only happens when an `organization_id` is configured. A global user (no org) gets a `DirectoryUser`
identity but no grants — there's no membership to scope them to.
:::

## Protected roles (no group-map escalation)

`rolesToGrant()` computes `default_roles ∪ mapped_roles`, then **subtracts `protected_roles`**:

```php
// effective = (default ∪ mapped) − protected
$protected = ['iam:super_admin'];
// even if group_map says 'some-group' => 'iam:super_admin', it is filtered out here.
```

So a wrong or compromised `group_map` row can never escalate a user to a protected role. Those roles remain
**manual-assignment only**.

## Fail-closed transport

`DirectoryConnector::authenticate()` returns `null` for invalid credentials — which becomes
`DirectoryOutcome::denied()`. No exception leaks, no IAM record is touched, nothing fails open.

## Test guardrails (don't regress these)

- email collision with a non-directory account → `conflict` (not `linked`);
- user removed from a mapped group → role **revoked** on next sync;
- `group_map` mapping a protected role → role **not** granted;
- invalid credentials → `denied`, no user/grant created;
- second login → idempotent (no duplicate grants).
