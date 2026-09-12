# Historical Search Integration (Desk Global Search)

**Prompt ID:** `RadiumDesk-P-07-09-281`  
**Status:** Implemented — disabled by default until production env enables it.

---

## Overview

Desk Global Search includes an optional **read-only** provider for the isolated canonical database `radium_hist` (Gate 14). Historical results are **non-authoritative** and never merged into live Desk transactional tables.

| Property | Value |
|----------|-------|
| Provider class | `App\Services\GlobalSearch\HistoricalGlobalSearchProvider` |
| Repository | `App\Services\HistoricalSearch\CanonicalHistoricalSearchRepository` |
| Connection | `radium_hist` (`config/database.php`) |
| Desk DB writes | **None** |
| Historical DB writes | **None** (read-only `radium_hist_ro`) |
| Add to Desk | **Not in this gate** |

---

## Search fields (Gate 12 / 14 validated)

| Lookup | Source |
|--------|--------|
| Order ID / reference | `hist_search_document.token_exact` |
| Customer email | `hist_search_document.email_norm` |
| Customer phone | `hist_search_document.phone_norm` |
| Customer name | `hist_search_document.name_search` (prefix) |
| Invoice | `hist_search_document.token_exact` |
| Serial | `hist_serial` (normalized whitespace) |
| Product | `hist_order_item.product_ref` / `product_name` |
| AWB | `hist_shipment.awb` |
| Provenance | `source_lineage`, `source_database`, `source_table`, `source_pk` on hits |

---

## API response shape

`GET /search?q=...` (JSON) adds:

```json
{
  "historical_match_count": 1,
  "historical_results": [
    {
      "type": "historical",
      "document_type": "order",
      "source_lineage": "commerce_active",
      "source_lineage_label": "RS/RQ commerce (old_final) · radium_old_final",
      "is_authoritative": false,
      "historical_only": true
    }
  ],
  "historical_search": { "status": "ok", "open": false }
}
```

Desk-native `match_count`, `results`, and `incident_ids` are unchanged (service cases only).

---

## Failure isolation

| Scenario | Behavior |
|----------|----------|
| `HISTORICAL_SEARCH_ENABLED=false` | Provider skipped; `historical_search.status=disabled` |
| Query timeout / DB error | Provider logs warning, records circuit failure, returns `[]` |
| Circuit open | Historical skipped for `HISTORICAL_SEARCH_CB_OPEN_SECONDS` |
| Provider exception | `GlobalSearchService` catches per-provider; Desk providers still run |

Desk orders, POS, payments, and Redis queues are unaffected.

---

## Configuration (`.env`)

```dotenv
HISTORICAL_SEARCH_ENABLED=false
HISTORICAL_SEARCH_TIMEOUT_MS=400
HISTORICAL_SEARCH_MAX_RESULTS=10
RADIUM_HIST_DB_HOST=127.0.0.1
RADIUM_HIST_DB_DATABASE=radium_hist
RADIUM_HIST_DB_USERNAME=radium_hist_ro
RADIUM_HIST_DB_PASSWORD=
# RADIUM_HIST_DB_SOCKET=/run/mysqld/mysqld.sock
```

On KVM8 production Desk, use localhost socket + `radium_hist_ro` credentials from `/opt/radium-hist-etl/.credentials` (not committed).

---

## Operations

- **Enable:** set `HISTORICAL_SEARCH_ENABLED=true` after read-only credentials verified.
- **Disable quickly:** set flag `false` or open circuit via repeated failures (auto).
- **Restore:** independent of Desk; see Gate 14 backup path `/opt/radium-hist-etl/backups/`.
- **Do not** point Desk at `radium_hist_etl` user in production search.

---

## Tests

- `tests/Feature/GlobalSearch/HistoricalGlobalSearchTest.php`
- `tests/Unit/HistoricalSearch/*`
