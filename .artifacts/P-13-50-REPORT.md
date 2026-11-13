# P-13-50 — Row 220 Ira Telegram bridge authority

| Global | **P-13-50** | Ledger | **RadiumDesk-P-13-50** |
| Baseline | **`6e4a4048`** (P-13-49) |

## Root cause — **TEST_SETUP** · Risk **LOW**

| Label | Finding |
| --- | --- |
| **VERIFIED** | Failed `assertTrue` after re-enabling `notifications.telegram.enabled`; first `assertFalse` passed. |
| **VERIFIED** | `setSystemSetting()` updated DB + `forget()` cache but not `SystemSettingsAdminCollection::invalidate()`; production `SystemSettingsService::set()` does both. |
| **VERIFIED** | Stale in-memory admin rows kept telegram **disabled** on second read within same test. |
| **VERIFIED** | `NotificationAuthorityService` Ira bridge path unchanged — only global channel + user telegram prefs. |

## Change

`NotificationAuthorityServiceTest::setSystemSetting()` — call `SystemSettingsAdminCollection::invalidate()`.

## Tests

| Scope | Exit | Result | ms |
| --- | ---: | --- | ---: |
| Row 220 filter | 0 | PASS | — |
| Full class | 0 | 10/10, 18 assert | 10171 |
| `IraTelegramCommunicationTest` | 0 | 17/17 | 16175 |

Full suite / pint: **NOT RUN**.
