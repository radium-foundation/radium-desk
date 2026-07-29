# BR-03 — Context Transparency (Foundation)

**Status:** Phase 1 implemented (foundation only)  
**Depends on:** [BR-02 Case / Customer Context Separation](br-02-case-customer-context-separation.md)  
**Last updated:** 2026-07-29

---

## Goal

Every surface in Radium Desk must **declare** which context it belongs to:

| Scope | Meaning |
|-------|---------|
| `CASE` | Active service case authority |
| `ORDER` | Linked order commercial lifecycle |
| `DEVICE` | Hardware / serial identity |
| `CUSTOMER` | Historical customer identity (phone/email) |

No feature should **infer** its context. Phase 1 establishes the platform types and catalog without changing UI, queries, or APIs.

---

## Phase 1 deliverables

| Piece | Location |
|-------|----------|
| `ContextScope` enum | `app/Enums/ContextScope.php` |
| `ContextBadge` value object | `app/Data/Context/ContextBadge.php` |
| `Customer360CardDefinition` | `app/Data/Context/Customer360CardDefinition.php` |
| `ProvidesContextScope` contract | `app/Contracts/Context/ProvidesContextScope.php` |
| `DeclaresContextScope` trait | `app/Support/Context/DeclaresContextScope.php` |
| `ContextTransparency` flag helper | `app/Support/Context/ContextTransparency.php` |
| Customer360 card catalog | `app/Support/Customer360/Customer360CardCatalog.php` |
| Feature flag | `config/context_transparency.php` → `CONTEXT_TRANSPARENCY_ENABLED` (default `false`) |

### Presenters adopting `ProvidesContextScope` (optional metadata only)

- `Customer360HealthCardPresenter` → CASE  
- `Customer360IraPanelPresenter` → CASE  
- `CaseIntelligenceV2OverviewPresenter` → CASE  
- `Customer360OverflowMenuPresenter` → CASE  
- `Customer360CommunicationActionStatusPresenter` → CASE  
- `Customer360InsightsPresenter` → CUSTOMER  

`contextBadge()` returns `null` when the flag is off; returns a `ContextBadge` when on. **`present()` / `build()` payloads are unchanged.**

---

## Customer360 card annotations (intended scopes)

See `Customer360CardCatalog::definitions()` for the full list. Highlights:

| Card | Intended scope |
|------|----------------|
| Timeline | CASE |
| Refund action | CASE |
| Appointments | CASE |
| IRA / Executive summary | CASE |
| Warranty / active services | ORDER |
| Serial / device section | DEVICE |
| Previous refunds / orders / communication | CUSTOMER |
| Recent calls (phone-wide) | CUSTOMER |

Notes on cards call out BR-02 debt where today’s data path still blends customer phone into a case surface.

---

## Feature flag

```env
CONTEXT_TRANSPARENCY_ENABLED=false
```

```php
config('context_transparency.enabled'); // false by default
ContextTransparency::enabled();
ContextTransparency::badgeFor(ContextScope::Case);
Customer360CardCatalog::badgeFor(Customer360CardCatalog::TIMELINE);
```

---

## Non-goals (Phase 1)

- No visible ContextBadge UI  
- No drawer / API payload changes  
- No query scope changes (BR-02)  
- No ContextResolver yet  

---

## Tests

- `tests/Unit/Context/ContextTransparencyFoundationTest.php`  
- `tests/Feature/Context/ContextTransparencyGoldenTest.php`  

Golden checks: drawer HTML has no badge markup with flag on or off; `drawerData` keys identical; catalog metadata available when enabled.

---

## Next phases (not in this PR)

1. Render badges when flag on (opt-in UI).  
2. Wire ContextResolver (BR-02).  
3. Enforce catalog scope in Case timeline / IRA fact collection.
