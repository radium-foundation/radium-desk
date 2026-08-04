# Team Activity Compact Presence Codes — Investigation & Implementation

**Prompt:** Compact NOC-style Presence codes  
**Date:** 2026-08-04  
**Type:** Presentation-only correction (previous UX used full-text labels)  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-compact-presence-codes-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/team-activity-compact-presence-codes-investigation.canvas.tsx)

---

## Bottom line

The previous Presence column showed sentence labels (`Active`, `Auto Logged Out`, …) with a separate duration/late stack. The approved UX is a compact Network Operations Center line: coloured dot + operational code + duration superscript, with Late as an inline secondary indicator.

| Before (wrong) | After (approved) |
|----------------|------------------|
| 🟢 Active / L¹³ᵐ on next line | 🟢 A¹ʰ³⁴ᵐ L¹³ᵐ |
| 🟠 Auto Log… truncated | 🟠 ALO²ʰ |
| 🔵 On Leave + leave reason line | 🔵 LV |
| ⚫ Not Logg… truncated | ⚫ NLI |

---

## Required format

| Status | Compact |
|--------|---------|
| Active | 🟢 A¹ʰ³⁴ᵐ |
| Idle | 🟡 I¹⁸ᵐ |
| Pending | 🟣 P¹⁰ˢ |
| Auto Logged Out | 🟠 ALO²ʰ |
| On Leave | 🔵 LV |
| Not Logged In | ⚫ NLI |
| Shift Not Started | ⚪ SNS |
| No Schedule | ⚪ NS |

Late is operational, not primary:

- 🟢 A¹ʰ³⁴ᵐ L¹³ᵐ
- 🟠 ALO²ʰ L⁴⁴ᵐ
- 🟣 P¹⁰ˢ
- ⚫ NLI

---

## Rules

| Rule | Applied |
|------|---------|
| Merge state duration into status code | Superscript on code |
| Remove separate duration line | Yes |
| Keep coloured status dot | Yes |
| Keep tooltip legend | Yes (extended for B / BR / SE / OFF) |
| No attendance / presence / resolver / payroll changes | Yes |
| Presentation only | Yes |

---

## Implementation

### Visual structure (single line)

```
[dot] [CODE][durationˢᵘᵖ] [L][lateˢᵘᵖ]
```

Examples rendered by `live-presence--compact`:

- `A` + `1h 34m` superscript + optional `L` + `13m` superscript
- `LV` with `title="Annual Leave"` (reason on hover / aria only)

### Code map (`TeamActivityPresenceLegend::codeFor`)

| Code | Statuses |
|------|----------|
| A | Working / login / assignment / … |
| I | Idle |
| P | Waiting customer (Pending) |
| B | On IVR / email / WhatsApp / IRA (Busy) |
| BR | Break |
| ALO | Auto Logged Out |
| LV | On Leave |
| NLI | Not Logged In |
| SNS | Shift Not Started |
| SE | Shift Ended |
| NS | No Schedule |
| OFF | Offline / unknown |

### Duration source (presentation only)

| Status family | Duration |
|---------------|----------|
| Active / Idle / Break | `currentDurationLabel` |
| Pending / Busy | `latestElapsed` (fallback current) |
| ALO | current / latest when available |
| LV / NLI / SNS / NS / OFF | none |

### Accessibility

Visual codes stay compact. `aria-label` keeps full words (`Active · 37m · Late 33m`, `On Leave · Annual Leave`). Legend ⓘ still explains abbreviations.

---

## Files modified

| File | Change |
|------|--------|
| `TeamActivityPresenceLegend.php` | `codeFor()` + legend entries for Busy/Break/SE/OFF |
| `TeamActivityMemberStatusPresenter.php` | `statusCode`, `stateDurationLabel`, compact aria |
| `live-presence.blade.php` | Single-line code + duration + late |
| `team-activity-agent-row.blade.php` | Pass code/duration; drop secondary line |
| `app.css` | Compact inline presence styles |
| Presenter / legend / UI / feature tests | Assert codes, not full labels |

**Not modified:** attendance, presence engine, status resolver, panel queries, payroll.

---

## Test results

```bash
php artisan test --filter='TeamActivityMemberStatusPresenterTest|TeamActivityPresenceLegendTest|DashboardTeamActivityUiTest|DashboardTeamActivityTest::test_off_duty_and_leave|DashboardTeamActivityTest::test_team_activity_refresh_returns_panel_html_for_authorized'
```

Coverage:

- Codes A / I / P / ALO / LV / NLI / SNS / NS
- Duration merged into Active/Idle/Pending
- Late inline; suppressed for leave / non-late
- Leave shows `LV` with aria/title reason — no full-text Presence label
- Legend retained

---

## Rollback strategy

1. Restore full-text `status-badge` label in `live-presence`.
2. Revert presenter to sentence aria + secondary context line.
3. Revert CSS compact rules.
4. Revert tests to full-label assertions.

No migrations or API changes.

---

## Success criteria

Presence reads as a compact NOC dashboard: `🟢 A¹ʰ³⁴ᵐ L¹³ᵐ`, not sentence labels — while legend + aria preserve meaning for new users and assistive tech.
