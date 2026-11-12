# P-12-41 matrix slice (P-12-32 CSV rows 75–79)

| Row | Test data set | @ P-12-32 (`27b03d19`) | @ `main` `482d6154` (P-12-41 verify) |
| --- | --- | --- | --- |
| 75 | `admin` | STILL FAILING | **VERIFIED FIXED** |
| 76 | `customer_coordinator` | STILL FAILING | **VERIFIED FIXED** |
| 77 | `operations_admin` | STILL FAILING | **VERIFIED FIXED** |
| 78 | `support_agent` | STILL FAILING | **VERIFIED FIXED** |
| 79 | `support_specialist` | STILL FAILING | **VERIFIED FIXED** |

**Root cause (historical):** Sidebar JSON omitted `learning_center` destination with `title="Learning Center"` despite `email-intake.view` / manage permissions — tests asserted nav + IRA page content.

**Product fix (already on `main`):** `NavigationContextResolver::sidebar()` adds Learning Center when `IncomingEmailAccess::allowsView($user)` ([PR #188](https://github.com/radium-foundation/radium-desk/pull/188) **`304c5aa6`**).

**P-12-41 delta:** CI workflow `incoming-email-learning-center-rip.yml` runs access + phase-1 Learning Center PHPUnit on relevant path changes.
