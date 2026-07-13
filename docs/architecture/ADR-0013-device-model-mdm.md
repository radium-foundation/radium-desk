# ADR-0013: Device Model Master Data Management

**Status:** Accepted  
**Date:** 2026-07-13  
**Type:** Platform architecture  
**Audience:** Engineering, product, operations

---

## Problem

Radium Desk historically treated device models as free-text strings on orders (`orders.device_model`). Matching relied on ad-hoc string comparison (trim, case folding, whitespace collapse) against `device_models.name` and `device_models.code`.

This created several platform risks:

1. **String guessing** — business flows depended on presentation labels that can change.
2. **Import fragility** — RadiumBox, CSV, and API ingestion write vendor/legacy labels that do not match catalog names.
3. **Identity ambiguity** — `MFS110`, `MFS 110`, `MFS-110`, and `Morpho MFS110` are the same device identity but different strings.
4. **Unstable coupling** — any future feature that compared presentation names would break when labels were renamed for UX.

The platform already introduced a master table (`device_models`) and FK (`orders.device_model_id`). What was missing was a permanent **identity mapping** layer for legacy and inbound strings.

---

## Decision

Establish Device Model as Master Data with four explicit identities, and resolve all inbound strings through identity mapping — never through presentation-name guessing.

### Four identities

| Identity | Field | Mutability | Role |
|----------|-------|------------|------|
| Database Identity | `device_models.id` | Immutable | Primary key; FK target |
| Business Identity | `device_models.code` | Immutable | Stable business key for logic and integrations |
| Presentation Identity | `device_models.name` | Mutable | Display only |
| Legacy Identity | `device_model_aliases` | Admin-managed | Maps vendor/import/legacy labels to a DeviceModel |

### Platform rules

1. Business logic must operate on canonical identities only (`id` or `code`).
2. Incoming strings must resolve through `DeviceModelAliasResolver`.
3. No runtime fuzzy matching (no Levenshtein, no heuristic fallbacks).
4. Communication Actions remain unchanged; they already consume `device_model_id`.
5. Legacy `orders.device_model` text is preserved for backward compatibility and is never deleted by this ADR.
6. API payloads remain non-breaking; future payloads may add `device_model_code` / `device_model_name`.

---

## Architecture

```
Incoming String
      │
      ▼
DeviceModelAliasNormalizer
  trim → collapse whitespace → remove separators → lowercase
      │
      ▼
DeviceModelAliasResolver
  1. resolveByAlias(normalized)  → device_model_aliases
  2. resolveByCode(normalized)   → device_models.code
      │
      ▼
DeviceModel (canonical)
      │
      ▼
orders.device_model_id
```

### Components

| Component | Responsibility |
|-----------|----------------|
| `device_model_aliases` | Unique `normalized_alias` → `device_model_id` |
| `DeviceModelAlias` | Eloquent model; auto-normalizes on save |
| `DeviceModelAliasNormalizer` | Single reusable normalization algorithm |
| `DeviceModelAliasResolver` | `normalize()`, `resolve()`, `resolveByAlias()`, `resolveByCode()`, `warmLookup()` |
| Settings → Models → Aliases | Admin CRUD for alias maintenance |
| `device-models:backfill` | Uses resolver instead of inline string lookup |

### Normalization examples

| Input | Normalized |
|-------|------------|
| `MFS110` | `mfs110` |
| `MFS 110` | `mfs110` |
| `MFS-110` | `mfs110` |
| `Morpho MFS110` | `morphomfs110` |

Exact identity match only. Distinct normalized keys require distinct aliases.

### Seeding

On migrate and seed, existing `device_models.name` and `device_models.code` values are inserted as aliases so current exact/case-insensitive matches continue to resolve without runtime name comparison.

---

## Consequences

### Positive

- Platform moves from string guessing to identity mapping.
- Imports and backfill share one resolution path.
- Presentation names can change without breaking business logic.
- Alias collisions are prevented by unique `normalized_alias`.
- Communication Actions and existing APIs remain stable.

### Negative / trade-offs

- Unmapped labels remain unmatched until an alias is created (intentional — no silent guessing).
- Operators must maintain aliases for new vendor labels.
- Codes must be populated for code-based resolution to be useful.

### Explicit non-goals

- Do not remove `orders.device_model` legacy text.
- Do not change `device_model_id` semantics.
- Do not add runtime fallback matching.
- Do not modify Communication Actions as part of this work.

---

## Future guidance

1. **Business logic must never compare device model presentation names.**
2. Prefer `device_model_id` for persistence and joins; prefer `device_models.code` for stable cross-system identity.
3. All new ingestion paths (RadiumBox, CSV, API) must call `DeviceModelAliasResolver::resolve()` and persist `device_model_id`.
4. When a new vendor label appears in unmatched backfill reports, add an alias in Settings — do not add heuristic matching in code.
5. Future API responses may expose `device_model_code` and `device_model_name` without breaking existing payloads.
6. Serial-pattern aliasing (`ProductModelAliasNormalizer` / `serial_pattern_profile_aliases`) remains a separate domain and must not be conflated with Device Model MDM.

---

## Related implementation

- Migration: `database/migrations/2026_07_13_160000_create_device_model_aliases_table.php`
- Services: `DeviceModelAliasNormalizer`, `DeviceModelAliasResolver`, `DeviceModelAliasSettingsService`
- Command: `device-models:backfill`
- Admin: Settings → Models → Aliases
- Tests: unit + feature coverage for normalization, resolution, duplicates, admin CRUD, backfill, and import resolution
