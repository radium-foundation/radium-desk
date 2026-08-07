# Platform Health heartbeats Git tracking

**Date:** 2026-08-07  
**Status:** Fixed (untracked + gitignored; deploy hardened)

## Verdict

`storage/framework/platform-health/platform-health-heartbeats.json` is a **runtime-generated** durable marker written by `PlatformHealthCache`. It was accidentally committed in `685cc84` (`fix(platform): stabilize snapshot regeneration pipeline`) and must not be tracked.

## Findings

| Question | Answer |
| --- | --- |
| Runtime-generated? | Yes — `PlatformHealthCache::writeDurable()` / `recordSchedulerHeartbeat` / `recordPresenceTimeoutRun` |
| Why tracked? | Committed with the durable-heartbeat feature instead of a directory stub `.gitignore` |
| Other `storage/framework` runtime artifacts tracked? | No — only `.gitignore` stubs remain under `storage/` after this fix |
| Stub directory preserved? | Yes — `storage/framework/platform-health/.gitignore` keeps the directory like `sessions/`, `cache/`, `views/` |

### Durable path behavior

- Production/local: `storage/framework/platform-health/platform-health-heartbeats.json`
- Unit/feature tests: `storage/framework/testing/platform-health-heartbeats.json` (already covered by `testing/.gitignore`)
- Missing file/directory is safe: writers create the directory; readers treat missing keys as null

### Deploy risk

Remote `git pull` can fail when a tracked heartbeat file has been rewritten by the scheduler (`local changes would be overwritten by merge`). `tools/commands/deploy.sh` now discards local mods to that path when it is still in the Git index, then pulls.

## Files changed

| File | Change |
| --- | --- |
| `storage/framework/platform-health/platform-health-heartbeats.json` | Removed from Git tracking (local file may remain; ignored) |
| `storage/framework/platform-health/.gitignore` | Stub: ignore `*`, keep `.gitignore` |
| `tools/commands/deploy.sh` | Pre-pull restore of tracked heartbeat path when present in index |
| `tools/README.md` | Deploy step note + troubleshooting row |
| `docs/platform-health-heartbeats-git-tracking-investigation.md` | This report |

## Rollback

1. Restore tracking of the JSON if required (not recommended):

```bash
git checkout HEAD~1 -- storage/framework/platform-health/platform-health-heartbeats.json
git rm -f storage/framework/platform-health/.gitignore
```

2. Revert deploy pre-pull heartbeat restore in `tools/commands/deploy.sh` and the troubleshooting note in `tools/README.md`.

3. Redeploy. Runtime heartbeats continue to work either way; rollback only reintroduces deploy friction from dirty remote JSON.
