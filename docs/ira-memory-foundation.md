# IRA Memory v4.0.5 — Foundation Architecture

**Date:** 2026-08-06  
**Version target:** 4.0.5  
**Status:** Architecture approved · Phase M1 implemented · Phase M2 implemented (service cutover) · M3+ not started  
**Canvas:** [`ira-memory-foundation.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/ira-memory-foundation.canvas.tsx)  
**Related:**
- [`docs/ira-learning-center-phase1.md`](ira-learning-center-phase1.md) — Learning Center (teach surface)
- [`docs/email-intake-disposition-workflow.md`](email-intake-disposition-workflow.md) — Teach vs disposition
- [`docs/email-intake-architecture-investigation.md`](email-intake-architecture-investigation.md) — Intake pipeline
- [`docs/ira-v2-intelligence-pipeline.md`](ira-v2-intelligence-pipeline.md) — Broader IRA intelligence

---

## 1. Objective

Evolve IRA from an **email rule engine** into a shared **business memory** layer.

One memory. Many channels.

This phase builds the architecture and the first admin surface. It does **not** build AI generation, suggestion approval workflows, scoring engines, conflict detectors, consolidation jobs, or expiration policies.

### Non-negotiables

| Constraint | Meaning |
|------------|---------|
| No Learning Rules regression | Existing teach → rule → pre-intelligence apply path keeps working |
| Memory is the abstraction | `IncomingEmailLearningRule` becomes a channel specialization of Memory |
| No silent AI rules | Operators (or later approved AI suggestions) create durable memory — never silent invent |
| Explainability first | Every applied memory must answer “Why did IRA choose this?” |
| Future-ready schema | Columns / statuses reserved for AI, approval, scoring, conflict, consolidation, expiration — unused for now |

---

## 2. Vision

IRA Memory is the central knowledge layer for:

| Channel / domain | Role in Memory |
|------------------|----------------|
| Email | First production source (Learning Rules migrate here) |
| WhatsApp | Future channel source |
| Service Cases | Case-derived patterns |
| Refunds | Refund-specific patterns |
| Appointments | Appointment-specific patterns |
| System Rule | Seeded / config-promoted deterministic memory |
| Call Notes | Future voice/call source |

```
Channels          Teaching surfaces           Shared layer              Consumers
─────────         ─────────────────           ────────────              ─────────
Email        →    Learning Center      ↘
WhatsApp     →    (future)              →    IRA Memory     →    Intake / routing / explainability
Refunds      →    (future)              ↗         │
Cases        →    disposition teach            Admin browser
Appointments →    (future)                     (search, edit, merge…)
Call Notes   →    (future)
System       →    seed / promote
```

**Learning Center** remains the operator teach/dispose queue for Email Intake.  
**IRA Memory** becomes Administration’s durable knowledge browser and management surface.

---

## 3. Current state (baseline)

### What exists today

| Asset | Location | Role |
|-------|----------|------|
| Learning Rules table | `incoming_email_learning_rules` | Only persistent operator-taught rule store |
| Rule model | `app/Models/IncomingEmailLearningRule.php` | Match key + decision + confidence + usage + `enabled` |
| Apply path | `IncomingEmailLearningRulesService::applyBeforeIntelligence()` | Runs **before** classifier / smart routing / AI |
| Teach path | `IncomingEmailLearningActionService` | Upserts rules from Learning Center |
| Disposition path | `IncomingEmailDispositionService` | May upsert ignore/spam/promo rules |
| Explainability | `IncomingEmailLearningCenterPresenter` | Card-level “why” for Needs Human |
| Config memory | `config/inbound_email.php` | Blocked senders/domains, keywords, priority phrases — **not** in DB rules |

### What does **not** exist today

- Admin browser for rules (search / filter / edit / merge / soft delete)
- Cross-channel memory store
- Related-memory graph
- Durable “example matches” collection (only live audit + `matched_learning_rule_id` on messages)
- Soft delete (only `enabled` boolean)
- Source / created-from provenance beyond `created_by`
- Memory types beyond email `decision_type` (`assign`, `classification`, `importance`, `ignore`)

### Unique key today

```
(rule_type, match_value, decision_type)
```

Re-teaching updates `decision_value` / confidence / creator semantics via upsert.

---

## 4. Architecture

### 4.1 Layering

```
┌──────────────────────────────────────────────────────────────────┐
│ Presentation                                                     │
│  • Learning Center (teach / dispose queues) — unchanged purpose  │
│  • Administration → IRA Memory (browse / manage) — NEW           │
│  • Per-message explainability panels — extended to Memory IDs    │
└────────────────────────────┬─────────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────────┐
│ Application services                                             │
│  • IraMemoryService          — CRUD, search, merge, soft delete  │
│  • IraMemoryQueryService     — filters, usage, related           │
│  • IraMemoryExplainService   — “Why did IRA choose this?”        │
│  • IncomingEmailLearningRulesService — adapter over Memory       │
│      (keeps public API; reads/writes Memory underneath)          │
└────────────────────────────┬─────────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────────┐
│ Domain                                                           │
│  • IraMemory (aggregate)                                         │
│  • Enums: MemoryType, MemorySource, MemoryStatus, PatternKind…   │
│  • Decision payload (typed by memory type / decision kind)       │
└────────────────────────────┬─────────────────────────────────────┘
                             │
┌────────────────────────────▼─────────────────────────────────────┐
│ Persistence                                                      │
│  • ira_memories                                                  │
│  • ira_memory_relations                                          │
│  • ira_memory_examples (optional Phase 1.1; initially derived)   │
│  • Compatibility: Learning Rule model / view during migration    │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2 Runtime position (email — unchanged order)

```
Ingest
  → Filter (config: spam / promo / system / blocked)
  → IRA Memory match + apply     ← was Learning Rules; same slot
  → Priority phrases (audit)
  → Matcher / Classifier / Smart routing / Needs Human
```

Ignore memories still short-circuit processing.  
Classification / Owner / Importance / Routing overrides still flow into the rest of the pipeline.

### 4.3 Abstraction contract

| Old concept | New concept | Notes |
|-------------|-------------|-------|
| Learning Rule | Memory | Same durable unit of knowledge |
| `rule_type` | `pattern_kind` | How the memory matches |
| `match_value` | `pattern_value` | Normalized match key |
| `decision_type` | `decision_kind` + `memory_type` | See mapping below |
| `decision_value` | `decision_value` | Unchanged string payload for email |
| `enabled` | `status` (`active` / `disabled` / …) | Soft delete is separate |
| `created_by` | `created_by_user_id` | Plus `created_from` provenance |
| `times_used` / `last_used_at` | same | Usage telemetry |
| `matched_learning_rule_id` | `matched_ira_memory_id` | Rename after cutover; dual-column during migration |

### 4.4 Separation of concerns

| Surface | Owns | Does not own |
|---------|------|--------------|
| **Learning Center** | Queue review, teach, dispose | Long-term memory curation |
| **IRA Memory admin** | Search, edit, merge, disable, delete, provenance | Clearing Needs Human |
| **Config / env** | Bootstrap filters & phrases until promoted | Operator-confirmed business memory |
| **AI (future)** | Suggestions only | Direct writes without approval |

Teach and dispose continue to **create / update** Memory rows. The new admin UI manages the corpus.

---

## 5. Memory taxonomy

### 5.1 Sources (supported now in schema; Email + System Rule wired first)

| Source | Value | Phase 1 wiring |
|--------|-------|----------------|
| Email | `email` | Yes — Learning Center + disposition |
| WhatsApp | `whatsapp` | Schema only |
| Refund | `refund` | Schema only |
| Service Case | `service_case` | Schema only |
| Appointment | `appointment` | Schema only |
| System Rule | `system_rule` | Yes — for promoted / seeded rows |
| Call Notes | `call_notes` | Future |

### 5.2 Memory types

| Memory type | Value | Intent | Email baseline mapping |
|-------------|-------|--------|------------------------|
| Classification | `classification` | How to classify this pattern | `decision_type=classification` |
| Owner | `owner` | Who owns / should be assigned | `decision_type=assign` |
| Ignore | `ignore` | Suppress / park as ignored | `decision_type=ignore` |
| Disposition | `disposition` | Preferred finish action for pattern | New (e.g. always create case) — schema ready; teach UI later |
| Customer Pattern | `customer_pattern` | Known customer behaviour | Future |
| Vendor Pattern | `vendor_pattern` | Vendor update / vendor action patterns | Partial via ignore action `vendor_update` today |
| Refund Pattern | `refund_pattern` | Refund-specific recognition / routing | Future (today: classification `refund` + smart route) |
| Routing Pattern | `routing_pattern` | Route / importance / pool behaviour | `decision_type=importance` + future smart-route memories |
| Appointment Pattern | `appointment_pattern` | Appointment-specific recognition | Future |

**Importance** is not a top-level Memory Type. It is stored as:

- `memory_type = routing_pattern`
- `decision_kind = importance`
- `decision_value = normal|high|escalation`

This preserves email behaviour while aligning with the shared taxonomy.

### 5.3 Pattern kinds (match axes)

| Pattern kind | Value | Origin |
|--------------|-------|--------|
| Sender | `sender` | Existing |
| Sender domain | `sender_domain` | Existing |
| Subject pattern | `subject_pattern` | Existing |
| Mailbox | `mailbox` | Existing |
| Keyword | `keyword` | Existing (match supported; teach UI still Phase 2) |
| Customer key | `customer_key` | Future |
| Order pattern | `order_pattern` | Future |
| Channel thread | `channel_thread` | Future |

### 5.4 Decision kind (operational facet)

Retained for email compatibility and explainability:

`assign` · `classification` · `importance` · `ignore` · `disposition`

`memory_type` answers “what kind of business knowledge is this?”  
`decision_kind` answers “what operational lever does it pull today?”

---

## 6. Data model

### 6.1 Table: `ira_memories`

Canonical store. Learning Rules migrate into this table.

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `uuid` | uuid, unique | Stable external / UI reference |
| `memory_type` | string(32) | Taxonomy §5.2 |
| `source` | string(32) | Taxonomy §5.1 |
| `pattern_kind` | string(32) | Former `rule_type` |
| `pattern_value` | string(255) | Former `match_value` (normalized) |
| `decision_kind` | string(32) | Former `decision_type` (+ `disposition`) |
| `decision_value` | string(255) | Target payload |
| `reason` | text, nullable | Human-readable “why this memory exists” |
| `confidence` | unsignedTinyInt | 1–100, default 80 |
| `status` | string(32) | `active`, `disabled`, `merged`, `deleted` |
| `times_used` | unsignedInt | Default 0 |
| `last_used_at` | timestamp, nullable | |
| `created_by_user_id` | FK users, nullable | System-seeded rows may be null |
| `created_from` | string(32) | `learning_center`, `disposition`, `system_seed`, `import`, `migration`, `manual_edit` |
| `created_from_type` | string(64), nullable | Morph class / logical type (e.g. incoming email message) |
| `created_from_id` | unsignedBigInt, nullable | Morph id |
| `merged_into_memory_id` | FK self, nullable | Set when status=`merged` |
| `expires_at` | timestamp, nullable | **Reserved — unused in Phase 1** |
| `suggestion_origin` | string(32), nullable | **Reserved** (`human`, `ai`, `system`) |
| `approval_status` | string(32), nullable | **Reserved** (`approved`, `pending`, `rejected`) |
| `score` | decimal(8,4), nullable | **Reserved** — memory scoring |
| `metadata` | json, nullable | Extensibility (conflict flags, AI payload stubs, etc.) |
| `created_at` / `updated_at` | timestamps | |
| `deleted_at` | timestamp, nullable | Soft delete (Eloquent); `status=deleted` kept in sync |

#### Indexes

| Index | Columns | Purpose |
|-------|---------|---------|
| Match | `(status, pattern_kind, pattern_value)` | Runtime matching (active only in query) |
| Type browse | `(memory_type, status, last_used_at)` | Admin filters |
| Source browse | `(source, status)` | Admin filters |
| Usage | `(times_used)`, `(last_used_at)` | Sort |
| Merge pointer | `(merged_into_memory_id)` | Merge graph |
| Unique active | unique `(pattern_kind, pattern_value, decision_kind)` **where status in (active, disabled)** | Preserve today’s upsert semantics without blocking soft-deleted history |

Exact unique implementation: partial unique index (PostgreSQL) **or** application-enforced uniqueness + composite unique including a `uniqueness_guard` column that flips on soft delete. Choose based on production DB engine at implementation time; document the chosen approach in the migration PR.

### 6.2 Table: `ira_memory_relations`

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `memory_id` | FK `ira_memories` | |
| `related_memory_id` | FK `ira_memories` | |
| `relation_type` | string(32) | `related`, `duplicate_of`, `supersedes`, `conflicts_with` |
| `created_by_user_id` | FK users, nullable | |
| `created_at` / `updated_at` | timestamps | |

Unique: `(memory_id, related_memory_id, relation_type)`.

`conflicts_with` is **schema-ready only** — no detector in this phase.

### 6.3 Example matches (Phase 1 approach)

**Do not block Phase 1 on a new examples table.**

Initial strategy:

1. Derive example matches from `incoming_email_messages.matched_learning_rule_id` / `matched_ira_memory_id` (last N).
2. Derive from audit events `incoming_email.learning_rule_applied` / future `ira.memory_applied`.
3. Add `ira_memory_examples` when cross-channel examples are required:

| Column | Type |
|--------|------|
| `id` | bigint PK |
| `memory_id` | FK |
| `example_type` | string(32) — `email_message`, `whatsapp_message`, … |
| `example_id` | unsignedBigInt |
| `snippet` | string(500), nullable |
| `matched_at` | timestamp |
| Unique `(memory_id, example_type, example_id)` |

### 6.4 Linked rules / related memories (detail panel)

| Detail section | Source |
|----------------|--------|
| Linked Rules | Email-era alias: memories that share pattern or were created in the same teach batch (`metadata.teach_batch_id` optional); also `decision_kind` siblings for same pattern |
| Related Memories | `ira_memory_relations` |
| Example Matches | Derived query (§6.3) |
| Previous usage | `times_used`, `last_used_at`, recent apply audit rows |

### 6.5 Enums (new)

| Enum | Cases |
|------|-------|
| `IraMemoryType` | classification, owner, ignore, disposition, customer_pattern, vendor_pattern, refund_pattern, routing_pattern, appointment_pattern |
| `IraMemorySource` | email, whatsapp, refund, service_case, appointment, system_rule, call_notes |
| `IraMemoryStatus` | active, disabled, merged, deleted |
| `IraMemoryPatternKind` | sender, sender_domain, subject_pattern, mailbox, keyword (+ reserved future) |
| `IraMemoryDecisionKind` | assign, classification, importance, ignore, disposition |
| `IraMemoryCreatedFrom` | learning_center, disposition, system_seed, import, migration, manual_edit |

Keep existing `IncomingEmailLearning*` enums as adapters that map into these during transition.

### 6.6 Model mapping (email compatibility)

```
IncomingEmailLearningRule  (compat model / facade)
  table: ira_memories   (after migration)
  accessors:
    rule_type      ↔ pattern_kind
    match_value    ↔ pattern_value
    decision_type  ↔ decision_kind
    enabled        ↔ status === active
    created_by     ↔ created_by_user_id
```

Prefer a thin facade over forking business logic. New code calls `IraMemory` / `IraMemoryService`. Email pipeline continues to call `IncomingEmailLearningRulesService`, which becomes a Memory-backed adapter.

---

## 7. Migration strategy

Goal: **natural migration, zero functional regression.**

### Phase M0 — Inventory & dual naming (docs / this document)

- Freeze Learning Rules behaviour as the regression baseline (existing Feature tests).
- Publish Memory taxonomy and column map (this doc).

### Phase M1 — Additive schema (no cutover)

1. Create `ira_memories` (full schema) **or** rename+expand `incoming_email_learning_rules` in one migration with careful downtime assumptions.
2. Create `ira_memory_relations`.
3. Backfill every Learning Rule row → Memory row:

| From | To |
|------|----|
| `rule_type` | `pattern_kind` |
| `match_value` | `pattern_value` |
| `decision_type` | `decision_kind` |
| `decision_value` | `decision_value` |
| `confidence` | `confidence` |
| `created_by` | `created_by_user_id` |
| `times_used` / `last_used_at` | same |
| `enabled=true` | `status=active` |
| `enabled=false` | `status=disabled` |
| — | `source=email` |
| — | `created_from=migration` |
| `decision_type=assign` | `memory_type=owner` |
| `decision_type=classification` | `memory_type=classification` |
| `decision_type=ignore` | `memory_type=ignore` |
| `decision_type=importance` | `memory_type=routing_pattern` |

4. Add nullable `matched_ira_memory_id` on `incoming_email_messages` alongside `matched_learning_rule_id`.
5. Keep writing Learning Rule IDs until M2.

**Recommended physical approach:** expand-in-place rename of `incoming_email_learning_rules` → `ira_memories` with additive columns, plus a DB view or model alias named for the old table **only if** external SQL/reporting depends on the old name. If nothing external depends on the table name, rename + facade model is enough.

### Phase M2 — Dual-write / single-read

1. `IncomingEmailLearningRulesService` upserts through `IraMemoryService`.
2. Matching reads only from `ira_memories` where `status=active`.
3. On apply: set both `matched_learning_rule_id` and `matched_ira_memory_id` (same id if in-place rename; mapped id if copy strategy).
4. Learning Center + disposition paths unchanged at HTTP level.

### Phase M3 — Admin UI (IRA Memory)

Ship Administration → IRA Memory against `ira_memories` (this foundation’s product deliverable after schema).

### Phase M4 — Retire aliases

1. Stop writing `matched_learning_rule_id` (migrate column → rename).
2. Deprecate `IncomingEmailLearningRule` model (keep until all references gone).
3. Audit event rename: `incoming_email.learning_rule_*` → also emit `ira.memory_*` (keep old events during one release for log continuity).

### Rollback

- M1 additive: drop new tables / columns.
- In-place rename: reverse migration restores old column names; facade removed.
- Never destructive-delete historical Memory rows in rollback — prefer status restore.

### Data integrity checks (must pass before M2)

1. Row count Learning Rules = Memory rows with `created_from=migration` (or all email source rows).
2. Every enabled rule has `status=active` Memory.
3. Feature suite `IncomingEmailLearningCenterPhase1Test` + `IncomingEmailDispositionWorkflowTest` green.
4. Spot-check: applyBeforeIntelligence still short-circuits ignore and records usage.

---

## 8. UI — Administration → IRA Memory

### 8.1 Placement

```
Administration
  └── IRA Memory          ← NEW primary nav item
```

Learning Center stays at `/admin/incoming-emails` (queue workspace).  
IRA Memory lives at a new admin route, e.g. `/admin/ira-memory`.

Permissions: same gate as Learning Center initially (`update` on `SystemSetting` + inbound email enabled), with a dedicated policy hook reserved (`IraMemoryPolicy`) so channel expansion does not stay tied to email config forever.

### 8.2 List features (Phase 1 product)

| Feature | Behaviour |
|---------|-----------|
| Search | Pattern value, reason, decision value, creator name/email |
| Filter | Memory type, source, status, pattern kind, decision kind, confidence band, created-from, has usage |
| View | Row → detail |
| Enable / Disable | `active` ↔ `disabled` (disabled excluded from runtime match) |
| Edit | Pattern, reason, decision, confidence, memory type (with guardrails) |
| Merge duplicates | Select N → choose survivor → others `status=merged`, `merged_into_memory_id` set, relations written |
| Delete | Soft delete → `status=deleted` + `deleted_at` (excluded from match; recoverable in admin “Deleted” filter) |
| Columns | Pattern, Type, Source, Status, Confidence, Times used, Last used, Created by, Created from, Updated |

### 8.3 Detail panel

Every memory detail shows:

| Section | Content |
|---------|---------|
| Pattern | `pattern_kind` + `pattern_value` |
| Reason | `reason` (editable) |
| Source | `source` |
| Created | timestamp + created by + created from (+ link to originating email when morph present) |
| Last used | `last_used_at` |
| Times used | `times_used` |
| Confidence | numeric + band (reuse Learning Center confidence styling) |
| Status | active / disabled / merged / deleted |
| Decision | `memory_type` + `decision_kind` + resolved label for `decision_value` |
| Example Matches | Last N derived matches (sender/subject/preview links) |
| Linked Rules | Sibling memories for same pattern / teach batch |
| Related Memories | From `ira_memory_relations` |
| Explainability preview | Static render of how this memory would explain itself when matched |

### 8.4 Explainability contract (runtime + UI)

Every applied memory must answer: **Why did IRA choose this?**

| Field | Required |
|-------|----------|
| Matched Pattern | `pattern_kind` + `pattern_value` |
| Confidence | 1–100 |
| Decision | Human label of memory type + decision value |
| Previous usage | `times_used` + `last_used_at` |
| Reason | Stored reason when present |
| Source | Channel / created-from |

Presenter output shape (extends Learning Center payload):

```json
{
  "why": "…",
  "matched_pattern": { "kind": "sender_domain", "value": "vendor.com" },
  "confidence": 90,
  "decision": { "memory_type": "ignore", "kind": "ignore", "value": "always_ignore", "label": "Always ignore sender domain" },
  "previous_usage": { "times_used": 14, "last_used_at": "…" },
  "memory_id": 123,
  "source": "email",
  "examples": []
}
```

### 8.5 Merge UX (rules)

1. Operator selects 2+ memories with compatible pattern space (same `pattern_kind` + normalized `pattern_value`, or explicit override with warning).
2. Chooses survivor (keeps id).
3. System:
   - Moves usage counters (sum `times_used`; `last_used_at` = max)
   - Points losers → `merged_into_memory_id`
   - Sets loser `status=merged`
   - Writes `duplicate_of` / `supersedes` relations
   - Rebinds recent `matched_*` references optionally (batch job; not required synchronously)
4. Runtime match never returns `merged` / `deleted` / `disabled` rows.

### 8.6 Edit guardrails

| Edit | Allowed | Guard |
|------|---------|-------|
| Reason, confidence | Yes | Confidence 1–100 |
| Disable / enable | Yes | Immediate effect on match |
| Soft delete | Yes | Confirm; show usage count |
| Pattern value | Yes with warning | May orphan uniqueness; re-normalize like Learning Rules |
| Decision value | Yes | Must remain valid for `decision_kind` |
| Source | No (immutable after create) | Prevents provenance lies |
| created_from | No | System-owned |

---

## 9. Service design (implementation guide — not built in this doc phase)

| Service | Responsibility |
|---------|----------------|
| `IraMemoryService` | Create/update from teach & admin; enable/disable; soft delete; merge |
| `IraMemoryMatcher` | Candidate extraction + ranked match (extracted from LearningRulesService) |
| `IraMemoryExplainService` | Build explainability DTOs |
| `IraMemoryAdminPresenter` | List/detail payloads for admin UI |
| `IncomingEmailLearningRulesService` | Facade: map email message → matcher; preserve method signatures |

### Teaching write path (unchanged externally)

```
Learning action / disposition
  → existing Action/Disposition service
  → IraMemoryService::upsertFromTeaching(...)
  → ira_memories row
```

### Matching read path

```
Processor
  → LearningRulesService::applyBeforeIntelligence()
  → IraMemoryMatcher::match(source=email, candidates…)
  → apply decision + recordUsage + explain fields on message
```

---

## 10. Future roadmap (designed for — not implemented)

| Capability | Design hook | Explicitly out of scope now |
|------------|-------------|-----------------------------|
| AI Suggestions | `suggestion_origin=ai`, draft rows with `approval_status=pending` | No LLM calls, no auto-create |
| Human approval | `approval_status`; admin queue “Pending approval” | No approval workflow UI |
| Memory scoring | `score` column; feed from accept/reject + precision | No scorer job |
| Conflict detection | `relation_type=conflicts_with` + metadata flags | No detector |
| Rule consolidation | Merge API + future batch suggester | Manual merge only in Phase 1 UI |
| Memory expiration | `expires_at`; matcher excludes expired | No TTL job |
| WhatsApp / Cases / Refunds / Appointments / Call Notes sources | `source` enum | No channel writers |
| Keyword teach UI | `pattern_kind=keyword` already matchable | Still Learning Center Phase 2 |
| Config → Memory promotion | `source=system_rule`, `created_from=system_seed` | Manual/ops only later |
| Multi-decision packs | `metadata.pack_id` or child rows | Still one decision per memory row |

Release sequencing suggestion (post-foundation):

1. **4.0.5a** — Schema + adapter + regression green (no UI)
2. **4.0.5b** — Administration → IRA Memory list/detail/enable/edit/delete
3. **4.0.5c** — Merge + related memories + example matches polish
4. **Later** — AI suggestions behind approval; scoring; conflict; expiration; new channels

---

## 11. Risk assessment

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| Matching regression after rename/map | Email ignores / assigns wrong | Medium | Keep Feature tests as gate; dual-read period; shadow compare counts |
| Unique key change breaks upsert | Duplicate memories / failed teaches | Medium | Preserve logical unique on `(pattern_kind, pattern_value, decision_kind)` for active/disabled |
| Soft delete vs `enabled` confusion | Operators think delete ≠ disable | Medium | UI copy: Disable = pause; Delete = remove from corpus (recoverable) |
| Dual ignore paths (teach ignore vs disposition ignore) | Inconsistent Memory `created_from` | Medium | Normalize both through `IraMemoryService`; set `created_from` correctly |
| Config memory vs DB memory drift | Same sender blocked in config and Memory | Medium | Document two layers; later “promote config → system_rule memory” |
| Merge rebinds historical message FKs | Audit inconsistency | Low | Don’t rewrite history synchronously; show survivor in UI |
| Over-broad edit of pattern | Accidental mass match | Medium | Confirm modal + show predicted recent match count before save |
| Premature multi-channel schema misuse | Empty sources clutter UI | Low | Filter default `source=email`; hide empty sources |
| Performance of admin search | Slow list | Low | Indexes §6.1; paginate; defer examples |
| AI scope creep in 4.0.5 | Delays foundation | High if unmanaged | Hard scope line in this doc; no generation code in 4.0.5 PRs |

---

## 12. Files likely affected

### New (expected)

| File | Role |
|------|------|
| `docs/ira-memory-foundation.md` | This architecture (done) |
| `app/Models/IraMemory.php` | Canonical model |
| `app/Models/IraMemoryRelation.php` | Relations |
| `app/Enums/IraMemoryType.php` | |
| `app/Enums/IraMemorySource.php` | |
| `app/Enums/IraMemoryStatus.php` | |
| `app/Enums/IraMemoryPatternKind.php` | |
| `app/Enums/IraMemoryDecisionKind.php` | |
| `app/Enums/IraMemoryCreatedFrom.php` | |
| `app/Services/IraMemory/IraMemoryService.php` | |
| `app/Services/IraMemory/IraMemoryMatcher.php` | |
| `app/Services/IraMemory/IraMemoryExplainService.php` | |
| `app/Services/IraMemory/IraMemoryAdminPresenter.php` | |
| `app/Http/Controllers/IraMemoryAdminController.php` | |
| `resources/views/admin/ira-memory/*` | List + detail + merge |
| `resources/js/ira-memory-admin.js` | Admin interactions |
| `database/migrations/2026_08_XX_XXXXXX_create_ira_memory_tables.php` | Schema |
| `database/migrations/2026_08_XX_XXXXXX_migrate_learning_rules_to_ira_memory.php` | Backfill / rename |
| `tests/Feature/IraMemory/*` | Admin + migration + matcher tests |

### Existing (adapter / touch)

| File | Change |
|------|--------|
| `app/Models/IncomingEmailLearningRule.php` | Facade / table rename / accessors |
| `app/Models/IncomingEmailMessage.php` | `matched_ira_memory_id`; relation |
| `app/Services/IncomingEmail/IncomingEmailLearningRulesService.php` | Delegate to Memory matcher/service |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Upsert via IraMemoryService |
| `app/Services/IncomingEmail/IncomingEmailDispositionService.php` | Ignore/spam/promo → Memory |
| `app/Services/IncomingEmail/IncomingEmailLearningCenterPresenter.php` | Explainability via Memory IDs |
| `app/Services/IncomingEmail/IncomingEmailProcessorService.php` | No order change; verify adapter |
| `app/Http/Controllers/IncomingEmailAdminController.php` | Unchanged routes; may pass memory ids in JSON |
| `routes/web.php` | New `/admin/ira-memory` routes |
| Admin nav partial(s) | “IRA Memory” under Administration |
| `resources/css/app.css` | Admin Memory styles (extend `ira-*` language) |
| `database/migrations/2026_08_06_120000_create_incoming_email_learning_rules_table.php` | Historical; superseded by Memory migration |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Must remain green |
| `tests/Feature/IncomingEmail/IncomingEmailDispositionWorkflowTest.php` | Must remain green |
| `docs/ira-learning-center-phase1.md` | Cross-link: rules browser → IRA Memory |
| `CHANGELOG.md` | User-facing 4.0.5 notes when shipping (not before approval) |

### Explicitly untouched in foundation

| Area | Why |
|------|-----|
| AI / LLM providers | Out of scope |
| WhatsApp dispatch tables | Source reserved only |
| `ira_operational_memory_snapshots` | Different “memory” (ops brain snapshots) — do not conflate |
| Config `inbound_email.php` filters | Remain config until promotion story |

---

## 13. Testing strategy

| Layer | Must prove |
|-------|------------|
| Migration | Backfill completeness; unique invariants; enabled→status map |
| Matcher | Same fixtures as today’s Learning Rules tests |
| Teach / disposition | Still create Memory; Learning Center UX unchanged |
| Admin | Search/filter; enable/disable; edit; soft delete; merge |
| Explainability | Payload contains matched pattern, confidence, decision, previous usage |
| Negative | `disabled` / `merged` / `deleted` / expired(null) never match |

Baseline suites that must not regress:

- `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php`
- `tests/Feature/IncomingEmail/IncomingEmailDispositionWorkflowTest.php`
- Intake / smart routing Feature tests that assume pre-intelligence overrides

---

## 14. Success criteria for foundation completion

1. Architecture accepted (this document + canvas).
2. Schema + adapter plan agreed (in-place rename vs copy).
3. Learning Rules behaviour preserved under Memory abstraction.
4. Administration → IRA Memory supports: Search, Filter, View, Enable/Disable, Edit, Merge, Soft Delete, and displays Usage, Last used, Created by, Created from, Confidence, Status.
5. Detail shows Pattern, Reason, Source, Created, Last Used, Times Used, Confidence, Example Matches, Linked Rules, Related Memories.
6. Runtime explainability answers Why / Matched Pattern / Confidence / Decision / Previous usage.
7. No AI generation shipped.
8. No functional regression on Email Learning Center or disposition.

---

## 15. Open decisions (resolve at implementation kickoff)

| Decision | Options | Recommendation |
|----------|---------|----------------|
| Physical migration | In-place rename vs new table + backfill | **In-place rename + additive columns** if prod row count is small and no external SQL depends on old name |
| Unique index style | Partial unique vs guard column | Match production DB capabilities |
| Importance taxonomy | `routing_pattern` vs keep parallel type | **`routing_pattern` + `decision_kind=importance`** |
| Examples table in 4.0.5b | Derive vs materialize | **Derive first**; materialize when second channel lands |
| Nav label | “IRA Memory” vs “Memory” | **IRA Memory** under Administration |
| Policy | Reuse SystemSetting gate vs new policy | New `IraMemoryPolicy` stub; gate identical initially |

---

## 16. Summary

IRA Memory is the shared business knowledge layer. Email Learning Rules are the first memories. The Learning Center keeps teaching; Administration → IRA Memory manages the corpus. Schema and services are designed for many channels and for future AI-with-approval — without building those futures now, and without breaking the rule engine operators already rely on.

---

## 17. Phase M1 — Schema + Adapter Foundation (implemented)

**Date:** 2026-08-06  
**Status:** Implemented — infrastructure only  
**Scope completed:** Schema, expand-in-place rename, backfill, compatibility facade, dual message FK column  
**Explicitly not done (deferred to M2+):** matcher cutover, dual-write of `matched_ira_memory_id` on apply, IraMemoryService, Admin UI, AI

### 17.1 Migration approach

**Chosen:** expand-in-place rename (architecture recommendation).

| Step | Action |
|------|--------|
| 1 | Drop `incoming_email_messages.matched_learning_rule_id` FK |
| 2 | Rename `incoming_email_learning_rules` → `ira_memories` (IDs preserved) |
| 3 | Add Memory columns (uuid, memory_type, source, status, created_from, reserved AI columns, soft deletes, `uniqueness_guard`) |
| 4 | Backfill from legacy Learning Rule rows |
| 5 | Rename legacy columns → Memory names; drop `enabled` |
| 6 | Create indexes + unique `(pattern_kind, pattern_value, decision_kind, uniqueness_guard)` |
| 7 | Create `ira_memory_relations` |
| 8 | Add nullable `matched_ira_memory_id` (mirror existing matches) |
| 9 | Re-point `matched_learning_rule_id` FK → `ira_memories` |
| 10 | Create compatibility **VIEW** `incoming_email_learning_rules` (legacy column names) |

**Unique index decision:** MySQL does not support partial unique indexes portably with SQLite tests → used `uniqueness_guard` (default `live`) so soft-deleted / merged history can coexist without blocking live upserts.

**External dependency check:** No production SQL/reporting dependency found on the physical table name. Compatibility VIEW retained so `assertDatabaseHas('incoming_email_learning_rules', …)` and ad-hoc legacy reads keep working.

Migration file: `database/migrations/2026_08_06_140000_expand_learning_rules_into_ira_memories.php`

### 17.2 Backfill summary

| From (Learning Rule) | To (IRA Memory) |
|----------------------|-----------------|
| `rule_type` | `pattern_kind` |
| `match_value` | `pattern_value` |
| `decision_type` | `decision_kind` |
| `decision_value` | `decision_value` (unchanged) |
| `confidence` | `confidence` |
| `created_by` | `created_by_user_id` |
| `times_used` / `last_used_at` | same |
| `enabled=true` | `status=active` |
| `enabled=false` | `status=disabled` |
| — | `source=email` |
| — | `created_from=migration` |
| — | `uuid` generated |
| — | `uniqueness_guard=live` |
| `decision_type=assign` | `memory_type=owner` |
| `decision_type=classification` | `memory_type=classification` |
| `decision_type=ignore` | `memory_type=ignore` |
| `decision_type=importance` | `memory_type=routing_pattern` |

Row IDs are unchanged. `matched_ira_memory_id` is backfilled from `matched_learning_rule_id` where present.

### 17.3 Compatibility layer

| Piece | Role |
|-------|------|
| `App\Models\IraMemory` | Canonical Memory model (`ira_memories`, SoftDeletes) |
| `App\Models\IraMemoryRelation` | `ira_memory_relations` |
| `App\Models\IncomingEmailLearningRule` | Facade extending `IraMemory`; legacy attributes `rule_type` / `match_value` / `decision_type` / `created_by` / `enabled` |
| `App\Models\Builders\IncomingEmailLearningRuleBuilder` | Remaps legacy where-clause column names so `IncomingEmailLearningRulesService` stays unchanged |
| VIEW `incoming_email_learning_rules` | Legacy read shape for SQL / tests |
| `incoming_email_messages.matched_learning_rule_id` | Still written by runtime (unchanged in M1) |
| `incoming_email_messages.matched_ira_memory_id` | Column present; historical rows mirrored in M1; **runtime dual-write added in M2** (see §18) |

Enums added: `IraMemoryType`, `IraMemorySource`, `IraMemoryStatus`, `IraMemoryPatternKind`, `IraMemoryDecisionKind`, `IraMemoryCreatedFrom`, `IraMemoryRelationType`.

### 17.4 Files changed

| File | Change |
|------|--------|
| `database/migrations/2026_08_06_140000_expand_learning_rules_into_ira_memories.php` | M1 migration |
| `app/Models/IraMemory.php` | New |
| `app/Models/IraMemoryRelation.php` | New |
| `app/Models/IncomingEmailLearningRule.php` | Compatibility facade |
| `app/Models/Builders/IncomingEmailLearningRuleBuilder.php` | Query column remap |
| `app/Models/IncomingEmailMessage.php` | `matched_ira_memory_id` + relation |
| `app/Enums/IraMemory*.php` | Taxonomy enums (7) |
| `tests/Feature/IraMemory/IraMemoryPhaseM1MigrationTest.php` | Schema / backfill / rollback / facade tests |
| `docs/ira-memory-foundation.md` | This Phase M1 section |

**Unchanged (intentional):** `IncomingEmailLearningRulesService`, Learning Center UI, disposition services, processor pipeline order, matcher algorithm.

### 17.5 Test results

| Suite | Result |
|-------|--------|
| `tests/Feature/IraMemory/IraMemoryPhaseM1MigrationTest.php` | Passed (10) |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Passed |
| `tests/Feature/IncomingEmail/IncomingEmailDispositionWorkflowTest.php` | Passed |
| `tests/Feature/IncomingEmail/IncomingEmailSmartRoutingTest.php` | Passed |
| Related auto-create / intake / closed-case reopen filters | Passed (53 combined with routing filter run) |

Verified: row-count preservation on expand-in-place backfill, indexes/FKs, rollback restores legacy table shape, facade `enabled` ↔ `status=active`, importance → `routing_pattern`.

### 17.6 Rollback strategy

`php artisan migrate:rollback` (this migration):

1. Drop compatibility VIEW  
2. Drop `matched_ira_memory_id`  
3. Drop `ira_memory_relations`  
4. Restore `enabled` from `status`  
5. Rename Memory columns back to Learning Rule names; drop Memory-only columns  
6. Rename `ira_memories` → `incoming_email_learning_rules`  
7. Restore `matched_learning_rule_id` FK onto the legacy table  

Data rows are preserved (not deleted). Prefer rollback only in non-prod or immediately after failed deploy before M2 dual-write begins.

### 17.7 Risks before Phase M2

| Risk | Notes |
|------|-------|
| Runtime still writes only `matched_learning_rule_id` | New applies leave `matched_ira_memory_id` null until M2 dual-write |
| Facade defaults `created_from=learning_center` on new rows | Disposition-created rules are not labeled `disposition` until M2 service path |
| Compatibility VIEW is read-only | Any raw `INSERT` into `incoming_email_learning_rules` will fail — must use models / `ira_memories` |
| `uniqueness_guard` discipline | M2 merge/soft-delete must flip guard away from `live` or unique upserts will collide |
| Do not start Admin UI / AI on incomplete dual-write | Wait for M2 adapter cutover review |

**M1 gate for M2:** approved after review. Do not begin M2 until this phase is signed off.

---

## 18. Phase M2 — Service Cutover (implemented)

**Date:** 2026-08-06  
**Status:** Implemented — knowledge service cutover only  
**Scope completed:** `IraMemoryService` / `IraMemoryMatcher`, Learning Center + disposition write path via `upsertFromTeaching`, canonical active-memory matcher, dual FK apply (`matched_learning_rule_id` + `matched_ira_memory_id`), correct `created_from` provenance  
**Explicitly not done (deferred to M3+):** Administration → IRA Memory UI, explain/admin presenters, AI, retire `matched_learning_rule_id` / facade

### 18.1 Cutover strategy

1. Introduce `App\Services\IraMemory\IraMemoryService` as the canonical CRUD / teach / merge / activate / disable / match / usage API.
2. Introduce `App\Services\IraMemory\IraMemoryMatcher` reading only `ira_memories` where `status=active` (and email `source`).
3. Keep `IncomingEmailLearningRulesService` as the email adapter:
   - `upsertFromOperatorTeaching()` → `IraMemoryService::upsertFromTeaching()`
   - `matchesFor()` / `applyBeforeIntelligence()` → Memory matcher + dual FK write
4. HTTP Learning Center / disposition endpoints unchanged.
5. Compatibility facade `IncomingEmailLearningRule` remains for legacy reads, view assertions, and transitional callers.
6. On apply and teach-link: write **both** `matched_learning_rule_id` and `matched_ira_memory_id` (same id after expand-in-place rename).

### 18.2 Created-from provenance

| Path | `created_from` |
|------|----------------|
| Learning Center teach | `learning_center` |
| Disposition ignore/spam/promo persist | `disposition` |
| Direct `IraMemoryService` system seed | `system_seed` (caller-supplied) |
| M1 backfill | `migration` (unchanged) |
| Manual / import helpers | `manual_edit` / `import` (caller-supplied) |

Re-teaching an existing live memory updates decision/confidence/creator and re-activates; it does **not** overwrite `created_from`.

### 18.3 Compatibility

| Piece | M2 behaviour |
|-------|----------------|
| `IncomingEmailLearningRule` | Still operational facade over `ira_memories` |
| VIEW `incoming_email_learning_rules` | Unchanged |
| Learning Center HTTP | Unchanged routes/payloads |
| Disposition HTTP | Unchanged routes/payloads |
| Processor order | Unchanged (Memory match still before intelligence) |
| Audit events | Still `incoming_email.learning_rule_*` (M4 may add `ira.memory_*`) |

### 18.4 Files changed

| File | Change |
|------|--------|
| `app/Services/IraMemory/IraMemoryService.php` | New — create/update/upsertFromTeaching/merge/activate/disable/match/usage |
| `app/Services/IraMemory/IraMemoryMatcher.php` | New — canonical active `ira_memories` matcher |
| `app/Services/IraMemory/IraMemoryMatch.php` | New — match DTO |
| `app/Services/IncomingEmail/IncomingEmailLearningRulesService.php` | Adapter over IraMemoryService; dual FK on apply |
| `app/Services/IncomingEmail/IncomingEmailLearningActionService.php` | Pass `created_from=learning_center`; dual FK on teach |
| `app/Services/IncomingEmail/IncomingEmailDispositionService.php` | Pass `created_from=disposition`; dual FK on persist |
| `tests/Feature/IraMemory/IraMemoryPhaseM2ServiceCutoverTest.php` | New M2 suite |
| `docs/ira-memory-foundation.md` | This Phase M2 section |

**Unchanged (intentional):** Learning Center UI, disposition UI, processor pipeline order, smart-routing algorithm, AI, Admin IRA Memory UI (M3).

### 18.5 Tests

| Suite | Result |
|-------|--------|
| `tests/Feature/IraMemory/IraMemoryPhaseM2ServiceCutoverTest.php` | Passed (9) |
| `tests/Feature/IraMemory/IraMemoryPhaseM1MigrationTest.php` | Passed |
| `tests/Feature/IncomingEmail/IncomingEmailLearningCenterPhase1Test.php` | Passed |
| `tests/Feature/IncomingEmail/IncomingEmailDispositionWorkflowTest.php` | Passed |
| `tests/Feature/IncomingEmail/IncomingEmailSmartRoutingTest.php` | Passed |

M2 coverage includes: teach → `ira_memories`, teach update, matcher active-only, dual ID apply + usage, legacy facade/view, assign → learning owner (sales path input), Learning Center regression, disposition `created_from`, merge/activate/disable.

### 18.6 Known risks before Phase M3

| Risk | Notes |
|------|--------|
| Dual FKs must stay in sync | M3/M4 must not write only one column until legacy FK retired |
| Facade still writable | Prefer `IraMemoryService` for new code; direct facade creates default `created_from=learning_center` |
| Ops vs knowledge `IraMemoryService` naming | `App\Services\Operations\IraMemoryService` is ops snapshots — unrelated to knowledge layer |
| Merge uniqueness_guard | Merged sources use `merged:{id}`; live unique index remains for `live` rows |
| No Admin UI yet | Operators still manage knowledge only via Learning Center / disposition teach |
| Sales consumers of `matched_ira_memory_id` | Dual-write now populates the column; confirm assignment consumers prefer learning owner / Memory assign decision consistently |
| Do not start AI on M2 alone | Wait for Admin browser (M3) + approval hooks |

**M2 gate for M3:** Learning Center + disposition + intake/routing regressions green; dual FK populated on apply; provenance correct. Do not begin Admin UI until this phase is signed off.
