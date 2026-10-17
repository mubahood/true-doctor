# Agent Execution Command — HMS_PLAN.md

Copy-paste the prompt below to the coding agent (Claude Code) from the repo root.

---

You are the implementation agent for this repository (`clinic-pro`, Laravel 12). Your single
source of truth is **`HMS_PLAN.md`** in the repo root. Execute it step by step, in order.
Do not invent scope, do not skip steps, do not reorder phases.

## Operating rules

1. **Read first, then act.** Before writing any code: read `HMS_PLAN.md` in full, then
   `ARCHITECTURE.md` and `README.md`, then skim `app/`, `routes/`, `database/migrations/` to
   ground yourself in the existing True-Doctor shell you are converting.

2. **Execute one numbered step at a time**, in the exact order of §7 (Phase 0 step 1 → step 2
   → … → Phase 5). For each step:
   - Restate the step and list the files you will create/modify.
   - Implement it fully — no stubs, no empty classes, no TODO placeholders (plan §3.A4 bans them).
   - Write/extend the tests that the step requires (plan §3.E21) and run the full suite.
   - Run `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (once configured in Phase 0),
     and `php artisan migrate:fresh --seed` to prove migrations are not silently broken.
   - Commit with message `HMS Phase <N> Step <n>: <summary>`. Never batch multiple steps
     into one commit.
   - Only then move to the next step.

3. **The constraints in §3 and the do-not-repeat register in §9 are hard rules.** Before every
   commit, self-review the diff against §9. In particular: no secrets in code or git, no
   default passwords, no logic in model boot hooks beyond data hygiene, no generic writes,
   FormRequest/DTO per write, enums for statuses, DB transactions around all financial
   operations, `BelongsToHospital` trait on every tenant model, `$fillable` + `$casts`
   complete on every model.

4. **Tenancy is safety-critical.** Every new tenant-scoped feature ships in the same step with
   an isolation test proving hospital A cannot read or write hospital B's rows (plan §2.1).
   A step is not complete without it.

5. **Definition of done per step:** code implemented + tests passing + Pint/PHPStan clean +
   `migrate:fresh --seed` green + isolation tests green + committed. If any check fails,
   fix it before proceeding — never carry a red build into the next step.

6. **Checkpoints.** Stop and report at the end of each **phase** (not each step) with:
   what was built, test coverage of the new code, any deviations from the plan and why,
   and what Phase N+1 will do. Wait for my "continue" before starting the next phase.

7. **When the plan is ambiguous**, choose the option most consistent with §2–§3 conventions,
   record the decision in `docs/decisions.md` (one line: date, decision, reason), and continue.
   Only stop to ask me if the choice affects data safety, money, or tenancy.

8. **When the plan conflicts with reality** (e.g., an existing file the plan assumed doesn't
   exist), do not silently improvise: note the conflict in the phase report and adapt minimally.

9. **Never touch** `_legacy/` (reference only), never edit shipped migrations after Phase 0
   (additive only), never commit `.env`, and never leave dead/duplicate code paths behind —
   if you replace something, delete the old path in the same commit (plan §3.A3).

10. **Documentation:** one canonical doc per feature in `docs/` updated in the same commit
    (plan §3.E23). Update `HMS_PLAN.md` only to tick progress, never to weaken constraints.

Begin now with **Phase 0, Step 1**.
