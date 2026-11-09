---
trigger: always_on
---

# EMC PFE Challenge: Subject 1 (Ticketing)

Symfony 7.4, PHP >= 8.2, MySQL 8, Doctrine ORM + migrations. Deadline: Sun 2026-11-15 23:59 (Morocco). Graded on method as much as result: depth over breadth.

## Git
- Small, focused commits with explicit messages (conventional style: `feat:`, `fix:`, `test:`, `docs:`, `chore:`).
- AI-written commits carry a `Co-authored-by: Antigravity <noreply@google.com>` trailer. Commits the user writes by hand (decision docs, etc.) carry none. The user owns design and decisions; the AI writes code from their specification.
- Never squash, rebase, amend pushed commits, or force-push `main`. History must stay intact.
- Push often.

## Document as you go
- Entity or schema change: update the Mermaid diagram and rationale in `docs/MODELE.md`, add one migration per logical change (never edit an applied migration), and update fixtures.
- Any assumption or ambiguity resolved: add a dated entry to `docs/DECISIONS.md` (context, options, choice, consequence). The five "Questions a trancher" from the subject must each get an entry.
- Business rule: write it in plain words in `docs/DECISIONS.md`, with a test that covers it. Seat-uniqueness and temporary-hold rules need concurrency tests.
- Feature done or changed: update the levels table in `README.md` (works / remaining).
- End of a work session: append a short note to `docs/ai-log/` (what was asked, what the AI got wrong, how it was caught). It feeds the AI-traces bonus and the one-page design note.

## Quality
- Every business rule ships with an automated test in `tests/`.
- Use DB transactions and locking (unique constraints, pessimistic or optimistic locks) for anything that must not double-sell.
- Realistic demo data via fixtures (one venue, several events, sales).

## Security
- No secrets or personal data in the repo, ever. Keep `.env.example` current; `.env.local` and credentials stay in `.gitignore`.
- Google Sheets service-account keys stay out of Git.

## Language
- Code and commit messages in English. Docs for the evaluators (`README.md`, `docs/`, `INSTALL.md`, design note) in French.
