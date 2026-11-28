# Watchdog alert hygiene — design (P-26-01 / P-28-27)

**Status:** Implemented (default-OFF flags).  
**Scope:** RadiumBox sync failures, queue dead-letter (`failed_jobs`), BonVoice webhook failures.

## Owner-locked rules

| Rule | Behavior |
| --- | --- |
| Actionable Critical window | **48 hours** (`ira.watchdog.actionable_hours`, default 48). Recent eligible failures may page Critical. |
| Aging | Failures **48h–30d** → evening digest / debt metrics only, not Critical. |
| Stale debt | Failures **older than 30d** (`ira.watchdog.stale_days`, default 30) → digest/debt only. |
| RadiumBox order ids | **RB\*** and **RD\*** recent failures eligible for Critical when hygiene ON (commerce/service public ids). |
| **CFPay_\*** | Never Critical; counted in digest/debt metrics (`excluded_cfpay`). Rows preserved. |
| Dead-letter | Actionable recent DLQ only for Critical; historical/recovered/duplicate backlog in evening metrics. No separate Critical page for old DLQ debt alone. |
| BonVoice | Downgrade verified **NO_CHANNEL** + missing `callID` noise in classifier only. No `ignored_terminal` writes. |
| Row preservation | No delete/prune/retry/mutation of `orders`, `failed_jobs`, or `bonvoice_webhook_logs` from hygiene paths. |

## Feature flags (code defaults **false**)

| Config key | Env |
| --- | --- |
| `ira.watchdog.radiumbox_hygiene_enabled` | `IRA_WATCHDOG_RADIUMBOX_HYGIENE_ENABLED` |
| `ira.watchdog.dead_letter_hygiene_enabled` | `IRA_WATCHDOG_DEAD_LETTER_HYGIENE_ENABLED` |
| `ira.watchdog.bonvoice_hygiene_enabled` | `IRA_WATCHDOG_BONVOICE_HYGIENE_ENABLED` |

When a flag is **false**, `ProductionWatchdogService::collectCriticalAlerts()` keeps legacy counts for that stream.

## Components

- `WatchdogRadiumBoxSyncFailureClassifier` — recency buckets + CFPay exclusion.
- `RadiumBoxWatchdogSyncFailureReadModel` — DB read + summarize for alerts/metrics.
- `WatchdogDeadLetterClassifier` — actionable vs historical/recovered/duplicate.
- `WatchdogBonvoiceWebhookFailureClassifier` — NO_CHANNEL downgrade.
- `WatchdogHygieneMetricsService::snapshot()` — evening health backlog/debt.
- `ProductionWatchdogService::collectParityAlerts(bool)` — legacy vs hypothetical hygiene (no uptime/gate side effects).
- `watchdog:alert-parity` — read-only comparison; no Telegram, no gate writes.

## Rollout

Enable per-stream flags in production only after Owner UAT. `watchdog:alert-parity` is diagnostic and does not change runtime classification unless env flags are set.