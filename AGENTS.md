# AGENTS.md — laravel-iam-directory

Guida rapida per agenti AI. Questo è il modulo **Directory** (LDAP/AD) dell'ecosistema Laravel IAM.

## Prima di lavorare (recupera contesto)
Leggi in ordine: **`LESSON.md`** (trappole già risolte, incluse quelle di sicurezza) → **`RULES.md`** →
**`CLAUDE.md`** (invarianti + architettura del package). Skill operativa: `laravel-iam-package-workflow`
(in `.claude/skills/`).

## Loop di lavoro (ADVISORY — mai autopilot)
1. Branch per task (`task/<nome>`); PR verso `main`; mai commit diretti su `main`.
2. Test verdi (Pest + PHPStan max + Pint, con PHP 8.5 via Herd).
3. Review advisory: `copilot -p "/review <diff vs origin/main> — focus: sicurezza, fail-closed, invarianti IAM"`.
   ⚠️ **MAI `copilot --autopilot --yolo`**: edita/commita/pusha in autonomia e ha già pushato codice regredito.
   I fix li applichi tu, mantenendo il controllo.
4. Loop remoto: CI verde + GitHub Copilot Code Review sulla PR → zero commenti → merge → tag.
5. Aggiorna `LESSON.md` ad ogni scoperta/fix.

## Attenzione particolare (sicurezza directory)
Questo modulo ha tre trappole classiche già chiuse — non regredirle: **anti-takeover** (email non-directory →
`conflict`), **no privilege persistence** (sync revoca i ruoli directory non più mappati), **`protected_roles`**
(mai concedibili via group mapping). Vedi `LESSON.md` §Specifiche.

## Commit & PR
- Commit terminano con: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`
- Corpo PR termina con: `🤖 Generated with [Claude Code](https://claude.com/claude-code)`
