# P-12-33 — PR #176 post-merge RIP / release-RIP verification

| Field | Value |
| --- | --- |
| Prompt | **RadiumDesk-P-12-33** (claimed) |
| Repository | `radium-foundation/radium-desk` |
| PR | [#176](https://github.com/radium-foundation/radium-desk/pull/176) — docs-only matrix reconciliation (P-12-32) |
| Merge commit | **`65cc1d28fe11898f7102fbefeba230159c264b89`** @ `2026-11-12T11:38:44Z` |
| PR head (pre-merge) | **`1fa17c2cc1865362d6f9b830691c06d2ac6ceaa6`** |
| `origin/main` after merge | **`65cc1d28`** (verified `git fetch` + `rev-parse`) |
| Wallet protected | **`feature/p18-33-unified-wallet-display`** @ **`d9481615`** — **not modified** |
| Policy | No application code; no full suite; no deploy; **no CI restarts** |

## Check matrix

| Check | Workflow | Job | Phase | `headSha` | Conclusion | Job completed (UTC) | Duration (job) | URL |
| --- | --- | --- | --- | --- | --- | --- | ---: | --- |
| **rip** | Availability sync RIP | `rip` | Pre-merge (PR head) | `1fa17c2c` | **PASSED** (`success`) | `2026-11-12T11:39:12Z` | ~32s | [43210912818](https://github.com/radium-foundation/radium-desk/actions/runs/43210912818) |
| **release-rip** | Release RIP | `release-rip` | Pre-merge (PR head) | `1fa17c2c` | **PASSED** (`success`) | `2026-11-12T11:39:17Z` | ~37s | [43210912919](https://github.com/radium-foundation/radium-desk/actions/runs/43210912919) |
| **rip** | Availability sync RIP | `rip` | Post-merge (`main` push) | `65cc1d28` | **PASSED** (`success`) | `2026-11-12T11:39:33Z` | ~44s | [43210931304](https://github.com/radium-foundation/radium-desk/actions/runs/43210931304) |
| **release-rip** | Release RIP | `release-rip` | Post-merge (`main` push) | `65cc1d28` | **PASSED** (`success`) | `2026-11-12T11:39:19Z` | ~30s | [43210931308](https://github.com/radium-foundation/radium-desk/actions/runs/43210931308) |
| Full PHPUnit suite | — | — | — | — | **NOT RUN** | — | — | — |
| `statutory-cancellation-phpunit` | — | — | — | — | **NOT RUN** | — | — | — |
| Production deploy / recovery-point capture | — | — | — | — | **NOT RUN** | — | — | — |

Recovery-point / release-RIP workflows above are **GitHub Actions** `Availability sync RIP` and `Release RIP` on the cited SHAs — not inferred from merge metadata alone.

## Artifact integrity on `main` @ `65cc1d28`

| Path | On `origin/main` | Notes |
| --- | --- | --- |
| `.artifacts/P-12-32-MATRIX-RECONCILIATION.md` | **YES** | Merged via PR #176 |
| `.artifacts/P-12-32-matrix-reconciliation.csv` | **YES** | Companion CSV |
| `.artifacts/reconcile-phpunit-full.json` | **YES** | On `main` under `.artifacts/` (not worktree-only) |
| `.worktrees/p12-32-matrix-reconcile/.artifacts/reconcile-phpunit-full.json` | Local worktree | Duplicate path from P-12-32 reconcile worktree; not required on `main` |

## P-12-32 follow-up

PR #176 body listed pre-merge CI as unchecked (`rip` / `release-rip` pending at merge). **P-12-33 closes that item:** both workflows **PASSED** on PR head and again on merge commit **`65cc1d28`**.
