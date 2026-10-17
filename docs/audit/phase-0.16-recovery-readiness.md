# Phase 0.16 - Recovery Readiness

Generated 2026-09-26

## Verdict: **NOT READY** for Phase 1 without a recovery plan

## What is recoverable today

| Asset | State | Recoverable |
|---|---|---|
| Database schema | 112 migrations applied, 0 pending | Yes, by re-running `migrate` from source |
| Database data | 74 tables | Only from a backup - **no backup evidence exists in this repo** |
| Application code | On disk | Only from VCS - **the project is not a Git repository** |
| Configuration | `.env` + `config/` | Partially - 55 keys, no required/optional split documented |
| Stored media | Local disk | Only from a backup |

## Blocking gaps

1. **No version control.** The project has no `.git` directory. There is no
   commit history, no branch, no tag, and no remote. "Revert the change" is not
   an available recovery action. This is the single largest recovery risk and
   it is not a code defect - it is a process gap.

2. **No documented backup or restore procedure.** No `mysqldump` schedule, no
   retention policy, and no restore drill. Combined with (1), a destructive
   migration or a bad deploy is unrecoverable.

3. **No documented rollback path for the 112 migrations.** `migrate:rollback`
   is not a safe default for a financial schema with settlement and commission
   history.

4. **No deployment runbook.** No documented order of operations, no health
   check to gate a release (`OBS-05`), no post-deploy smoke test.

## What would make this READY

- [ ] Initialise version control and push to a remote
- [ ] Write and rehearse a database backup + restore procedure
- [ ] Document which of the 55 env keys are required in production
- [ ] Add a health-check endpoint and gate deploys on it
- [ ] Document a rollback procedure per migration class
- [ ] Set `APP_DEBUG=false` in production and verify (`DEP-01`)

## Note on this phase
No recovery mechanism was added. Introducing backups, VCS or deployment
automation is an infrastructure decision, not a code change, and is recorded
as a Phase 1 entry condition instead.

