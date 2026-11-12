# P-12-22 remediation report (Radium Desk)

| Field | Value |
| --- | --- |
| **Prompt** | RadiumDesk-P-12-22 |
| **Baseline** | `origin/main` @ **`4a440083`** (#169 `2e6a07b5`, #170 merged) |
| **Worktree** | `.worktrees/p12-22-ws-10-markup` |
| **Branch / PR** | `feature/p12-22-ws-10` |
| **Matrix** | P-12-09 `p12-05-failure-matrix` — **WS-10 ≈ 117** (`assertion-data-mismatch` markup/display cluster) |
| **Policy** | Focused tests only; no full suite; no deploy; wallet WIP **`d9481615`** untouched |

---

## Root cause (WS-10 C360 / display parity)

Customer 360 duplicated order-ID presentation outside the shared **`x-order-identifier`** component:

- **Ops header** rendered plain `order_id` (no `legacy-imported-order-id` / indicator / Bootstrap tooltip).
- **Device card** used inline copy only (no legacy LIO marker).
- **Support appointments** section title used **`Scheduled support`** while tests (and copy) expect **`Scheduled Support`**.

Dashboard grid rows already used **`x-order-identifier`**; legacy dashboard assertion now targets **`GET dashboard/service-cases/{incident}/row`** JSON HTML (async shell no longer SSR-inlines all rows).

---

## Changes

| Area | File(s) |
| --- | --- |
| C360 ops header | `resources/views/components/c360/operations-header.blade.php` |
| C360 device card | `resources/views/components/c360/device-card.blade.php`, `customer-360/partials/*` |
| C360 appointments heading | `resources/views/customer-360/partials/support-appointments.blade.php` |
| Tests | `tests/Feature/LegacyOrder/LegacyOrderUiTest.php` (`withoutVite()`, dashboard row JSON) |

**Wallet / statutory:** no changes.

---

## Focused tests (local, `php -n`)

| Suite | Result |
| --- | --- |
| `LegacyOrderUiTest` | **7/7** |
| `SupportAppointmentBookingTest::test_customer_360_displays_scheduled_support_appointment` | **1/1** |

Full suite **NOT RUN**. `npm run build` **NOT RUN** (Blade-only; existing CSS classes unchanged).

---

## Remaining failure estimate

| Metric | Value |
| --- | --- |
| P-12-04 stable matrix total | **227** |
| WS-10 classified count | **117** |
| Non–WS-10 (inherited) | **227 − 117 = 110** |
| WS-10 proven fixed this PR (focused) | **8** assertions clusters |
| WS-10 remaining (estimate) | **~109** (117 − 8; not re-measured on full suite) |
| Matrix remaining (estimate) | **~219** (227 − 8) |

---

## Ledger

**Claimed:** `RadiumDesk-P-12-22` appended in `docs/cursor-prompt-ledger.md`.
