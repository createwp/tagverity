# TagVerity Enhancement Roadmap (2026-10-30)

**Document type:** Programme plan — sequences the approved enhancements and links
to one detailed implementation plan per phase.
**Approved by product owner:** 2026-10-30
**Excluded from this round:** Stripe checkout and webhook (A3).

## Team and Review Model

- **Implementers:** Haiku 5.5 subagents, one fresh agent per task, each given only
  its plan task plus the plan's Global Constraints.
- **Reviewer:** the main Opus 5.5 session reviews every task — reads the diff,
  runs the task's tests and `composer test` — before the next task starts. A task
  that fails review twice is re-dispatched on a stronger model.
- **Roles:** backend (PHP, Google APIs), frontend (vanilla JS/CSS pages),
  designer (uses the `frontend-design` and `design:*` skills; produces specs and
  CSS), security/QA (sanitiser, web exposure, tests, CI).
- **Isolation:** each phase runs on its own branch in a git worktree. Phases
  marked "parallel" touch disjoint files and may run at the same time.

## Phases

| # | Phase | Roles | Depends on | Plan |
|---|---|---|---|---|
| 1 | A1 Real audit data | backend, frontend | — | [`2026-10-30-a1-real-audit-data.md`](2026-10-30-a1-real-audit-data.md) |
| 1 | A4 Security, tests, CI (parallel with A1) | security/QA | — | [`2026-10-30-a4-security-tests-ci.md`](2026-10-30-a4-security-tests-ci.md) |
| 2 | A2 Close dead ends: audit history, domain map, monitoring placeholder, dompdf PDF export | frontend, backend, designer | A1 (outcome + coverage data) | written when phase 1 merges |
| 2 | C9 Design pass: reveal-panel redesign of Destinations + Tags & Triggers ([approved spec](../specs/2026-10-30-reveal-panel-redesign-design.md)), first-run/empty states, tracking-health dashboard, accessibility review | designer, frontend, backend (manual parameters) | A1 | written when phase 1 merges |
| 3 | B5 GTM deployment adapter: approved release → dedicated GTM workspace → version (publish stays approval-gated) | backend | A1 (GTM discovery + target selection) | written when phase 2 merges |
| 4 | B6 Fire verification via the live snippet | backend, frontend | B5 | written when B5 merges |
| 5 | B7 Destination test events + token health | backend, frontend | A1 | may run parallel with B6 |
| 5 | B8 AI event suggestions in the mapper (Claude API) | backend, frontend | — | may run parallel with B6 |
| 6 | Trigger builder: type picker, per-type config, sentence conditions, exceptions — needs its own spec + data model mapped to GTM trigger resources | designer, backend, frontend | B5 | spec written after B5 merges (approved in principle 2026-10-30; mockup artboard 3) |

Later-phase plans are written just before they start, because they consume
interfaces (audit target selection, source status, coverage view) that phase 1
creates.

## Shared Rules for Every Phase

- Follow the non-negotiable security model in `docs/PROJECT_STATUS.md`.
- Each schema change is a new numbered migration block appended to
  `run-migrations.php`, idempotent, and run twice locally.
- Each test is a standalone script in `tests/` that exits non-zero on failure;
  `composer test` (added in A4) runs them all.
- Update `docs/PROJECT_STATUS.md` (implemented, incomplete, concerns, change log)
  in the final task of each phase.
- Local PHP: `C:\wamp64\bin\php\php8.1.0\php.exe` (PATH points to 7.4).
