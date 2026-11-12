# P-12-38 — Ownership manifest (no file overlap)

| Agent | Worktree | Branch | Owned matrix rows | Owned paths |
| --- | --- | --- | ---: | --- |
| **A** IRA Learning Center | `.worktrees/p12-38-incoming-email-learning-center` | `feature/p12-38-incoming-email-learning-center` | **5** `IncomingEmailLearningCenterAccessTest` data sets | `tests/Feature/IncomingEmail/IncomingEmailLearningCenterAccessTest.php`; `app/Support/IncomingEmail/*`; incoming-email admin views/layouts as needed |
| **B** Automation alerts | `.worktrees/p12-38-intelligent-automation-alerts` | `feature/p12-38-intelligent-automation-alerts` | **4** `IntelligentAutomationAlertSemanticsTest` | `tests/Feature/IntelligentAutomationAlertSemanticsTest.php`; `app/Services/Operations/ProductionWatchdogService.php`; automation/telegram alert paths only |
| **C** POS statutory | `.worktrees/p12-38-pos-statutory-reference` | `feature/p12-38-pos-statutory-reference` | **5** `PosStatutorySnapshotTest` + statutory PDF rows in `StatutoryInvoicePdfCustomerFacingSkuTest` | `tests/Feature/Inventory/PosStatutorySnapshotTest.php`; `tests/Feature/StatutoryInvoice/StatutoryInvoicePdfCustomerFacingSkuTest.php`; POS snapshot + PDF presentation (STOP: statutory business without Owner) |
| **D** CI Imagick | `.worktrees/p12-38-ci-imagick-parity` | `feature/p12-38-ci-imagick-parity` | **1** ENVIRONMENT BLOCKED (Imagick / matrix #175 class) | `.github/workflows/*.yml` `setup-php` extensions; `docs/*` env notes; `tests/bootstrap.php` read-only unless harness-only |
| **E** Reviewer | `.worktrees/p12-38-reviewer` | `feature/p12-38-reviewer-readonly` | — | read-only cross-check |

## Exclusions (do not touch)

- Wallet WIP `feature/p18-33-unified-wallet-display` @ `d9481615` (main checkout)
- P-12-36 `.worktrees/p12-36-dashboard-service-cases`
- P-12-37 agent trees (historical; no edits)

## Conflict analysis

- **No overlapping** `*Test.php` between A–D.
- **C vs D:** C may run PHPUnit with local `imagick.so`; D owns CI YAML only. C must not weaken skips; D must not change product PDF semantics.
- **B vs C:** no shared files expected.
