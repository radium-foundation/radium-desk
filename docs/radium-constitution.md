# Radium Desk — Operations Intelligence Platform Constitution

**Version:** 1.0  
**Prompt / Review foundation:** P17-07-001 (Platform Engine Architecture Review)  
**Status:** Permanent product & architecture handbook  
**Audience:** Developers, architects, product managers, and future team members  
**Last updated:** 2026-07-17

This document is the long-term **Product & Architecture Constitution** for Radium Desk. It explains *why* the platform is shaped the way it is, and how every future feature should be judged.

It builds on:

- The validated Platform Engine Architecture Review (P17-07-001)
- The Platform Engine Canvas derived from that review
- The earlier [Product Constitution](product-constitution.md) (agent workspace principles)
- The [Master Architecture](radium-desk-v2-master-architecture.md) (system orientation)

When product intent and implementation diverge, **this constitution states the intended direction**. Implementation may lag; engines and modules catch up incrementally.

This is a philosophy and governance document. It deliberately avoids class-level implementation detail. For technical entry points and debt sequencing, use the Platform Engine review and related engine docs.

---

## Part 1 — Product Vision

### What Radium Desk is

**Radium Desk is an AI Powered Operations Intelligence Platform.**

It helps a business see what is happening across customers, work, people, assets, and money-in-motion — then automate the repetitive parts so humans spend their time creating value.

Radium Desk is not a traditional ticket tool. It is not a pile of disconnected modules. It is an orchestration layer that turns operational activity into a coherent story: what happened, who owns it, what is at risk, and what should happen next.

### Mission

> Give business owners complete operational visibility while automating repetitive work so teams spend more time creating value.

Visibility without automation creates dashboards nobody acts on.  
Automation without visibility creates opaque systems nobody trusts.  
Radium Desk exists to do both — and to keep humans accountable for decisions.

### What Radium Desk is not

Radium Desk must not become:

| Not this | Why |
|----------|-----|
| **ERP** | We orchestrate operations; we do not replace manufacturing, inventory, or enterprise resource planning systems of record |
| **HRMS** | We understand workforce presence, shifts, and capacity for operations — not full HR lifecycle |
| **Accounting software** | We surface operational finance signals (refunds, payments, settlements in context) — not a general ledger |
| **Payroll** | Compensation calculation and statutory payroll belong elsewhere |
| **Generic CRM** | We deepen customer operational truth (360 views, timelines, service outcomes) — not marketing pipeline CRM |

If a feature primarily belongs in one of those categories, it probably does not belong in Radium Desk — unless it is a thin operational bridge that preserves **one source of truth** in the owning system.

### How this evolves the earlier vision

The earlier constitution correctly established Radium Desk as an **Operations Workspace** with One Workspace, One Timeline, One Truth, and One Click — centered on agent excellence.

This constitution expands that foundation:

| Era | Focus | North star user |
|-----|--------|-----------------|
| Operations Workspace | Fast, accountable customer service | Support agent |
| Operations Intelligence Platform | Visibility + automation + AI-assisted judgment | Agent **and** owner |

Agents still work in workspaces. Owners must understand health of the business. Both are first-class.

---

## Part 2 — Core Principles

These principles govern product and architecture decisions. They are filters, not slogans.

### 1. Solve operational problems

Every feature must remove friction from real operations: waiting customers, unclear ownership, missed follow-ups, invisible risk, repetitive busywork.

If the problem is not operational, challenge the feature.

### 2. Objects before pages

Design **business objects** first (Customer360, Work360, Workforce360, …). Pages are projections of objects. Do not invent a page that invents a new truth.

### 3. 360 before CRUD

Prefer a complete operational picture over another create/edit form. CRUD exists to serve the 360 — not the other way around.

### 4. AI assists, humans decide

AI may summarize, score, recommend, draft, and prioritize.  
AI must not silently take irreversible customer-facing or ownership decisions without a human (or an explicit, auditable policy).

### 5. Explain every score

Any health score, risk score, priority, or confidence value must be explainable in plain language. Opaque numbers destroy trust.

### 6. Reuse engines, not business logic

Platform engines provide shared capability (timeline, audit, notify, assign, search, …).  
Domain services own business rules. Do not copy a business rule into an engine “for convenience,” and do not fork a new engine when an existing contract can be extended.

### 7. Configuration before customization

Prefer config, policies, registries, and eligibility rules over one-off code forks. Customization that cannot be expressed as configuration becomes permanent debt.

### 8. Every action creates knowledge

Important actions leave evidence: audit, timeline, searchable history, and eventually AI context. Work that vanishes is a product failure.

### 9. One source of truth

Each domain fact has one owner. Radium Desk may project, enrich, and orchestrate — it must not invent a second conflicting record for the same fact.

### 10. Simplicity wins

If an owner cannot understand it, or an agent needs a manual to use it, simplify. Complexity is a cost center.

### Continuity with One Workspace / One Timeline / One Truth

The classic product principles remain in force:

| Principle | Meaning in this constitution |
|-----------|------------------------------|
| **One Workspace** | Work happens in a coherent surface for the object in focus — not module hopping |
| **One Timeline** | The operational story is one projected history, not parallel logs |
| **One Click** | Frequent actions stay short-path |
| **One Truth** | Engines and integrations respect ownership boundaries |
| **One Goal** | Reduce wasted effort; increase value-creating time |

---

## Part 3 — 360 Objects

Long-term, Radium Desk is organized around **360 business objects**. Each 360 is a living operational picture — not a database table and not a menu item.

Common facets of every 360:

| Facet | Meaning |
|-------|---------|
| **Purpose** | Why this object exists for the business |
| **Health** | How we know if it is OK, at risk, or failing |
| **Timeline** | The story of what happened |
| **Relationships** | Which other 360s it connects to |
| **AI** | How intelligence helps without replacing judgment |
| **Actions** | What humans (and policy-bound automation) can do |
| **Audit** | How accountability is preserved |
| **Future vision** | Where the object is heading |

---

### Customer360

**Purpose**  
Understand one customer completely: identity, devices, orders, service history, communication, risk, and next best action.

**Health**  
Waiting time, open work, communication gaps, warranty/serial risk, sentiment and escalation signals — expressed as explainable health, not a mystery score.

**Timeline**  
The customer’s operational story across orders, service cases, messages, calls, appointments, and corrections.

**Relationships**  
Links to Work360 (cases/tasks), Asset360 (devices), Finance360 (payments/refunds in context), Knowledge360 (what we know that helps them).

**AI**  
Summaries, risk indicators, suggested replies and checklists — always reviewable by a human.

**Actions**  
Communicate, assign, schedule, correct data (governed), trigger approved automations.

**Audit**  
Every meaningful change and outbound communication is attributable.

**Future vision**  
The default place any team member starts when the question is “who is this customer, and what do they need?”

---

### Work360

**Purpose**  
See and run a unit of work end-to-end — today primarily service cases and related operational tasks; tomorrow a unified work object spanning queues and workflows.

**Health**  
SLA posture, ownership clarity, blockers, age, reassignment churn, customer wait.

**Timeline**  
Status, assignment, notes, communications, escalations, and outcomes as one story.

**Relationships**  
Customer360, Workforce360 (assignee/capacity), Asset360, Knowledge360, Operations360 (queue pressure).

**AI**  
Prioritization assistance, missing-info detection, draft resolutions — human confirms.

**Actions**  
Assign, escalate, communicate, resolve, schedule follow-ups, attach evidence.

**Audit**  
Ownership and state transitions are append-only history.

**Future vision**  
One work model that can represent service cases, operational tasks, and workforce-triggered work without separate mental models.

---

### Workforce360

**Purpose**  
Understand people as operational capacity: who is available, skilled, overloaded, on leave, or owning critical work.

**Health**  
Presence, schedule adherence, workload balance, skill coverage, burnout/overload signals (coaching-oriented, not surveillance theater).

**Timeline**  
Shift events, assignment history, presence changes, notable operational contributions.

**Relationships**  
Work360 (ownership), Operations360 (team/queue health), Customer360 (via work performed).

**AI**  
Capacity insights, fair-distribution recommendations, briefing summaries for leads.

**Actions**  
Schedule, assign within policy, hand off, mark presence — always policy-aware.

**Audit**  
Who changed schedules, assignments, and eligibility-affecting decisions.

**Future vision**  
The owner and ops lead can answer “can we take more work today?” in seconds.

---

### Asset360

**Purpose**  
Operational truth about devices and assets in the field: identity, serial, warranty context, service history, sync state with systems of record.

**Health**  
Missing/incorrect serial, warranty risk, repeated failures, sync freshness.

**Timeline**  
Installations, corrections, service events, syncs, communications about the asset.

**Relationships**  
Customer360, Work360, Knowledge360 (device guidance), Vendor360 (where relevant).

**AI**  
Failure pattern hints, recommended diagnostics, serial/warranty risk explanation.

**Actions**  
Request/correct serial, trigger device-relevant communications, open work.

**Audit**  
Identity and correction history is sacred — especially activation-critical fields.

**Future vision**  
Assets are first-class citizens, not footnotes on an order row.

---

### Knowledge360

**Purpose**  
Capture and serve what the organization knows: products, processes, playbooks, prior resolutions, and operational facts AI and humans both need.

**Health**  
Coverage, freshness, conflict rate, usefulness (was this knowledge used successfully?).

**Timeline**  
Knowledge updates, citations in work, supersessions.

**Relationships**  
Feeds AI Engine and all other 360s; draws from Work360 outcomes and Customer360 patterns.

**AI**  
Retrieval and drafting grounded in Knowledge360 — never invented policy presented as fact.

**Actions**  
Curate, approve, deprecate, attach knowledge to work and communications.

**Audit**  
Who changed guidance that agents and automation rely on.

**Future vision**  
Institutional memory that compounds with every resolved case.

---

### Operations360

**Purpose**  
See the business operating system: queues, throughput, bottlenecks, SLA estate, automation health, and team command visibility.

**Health**  
Backlog, breach risk, channel health, assignment fairness, automation failure rate.

**Timeline**  
Operational incidents (system and process), major shifts in load, policy changes.

**Relationships**  
Aggregates Work360, Workforce360, Notification/Automation health, Search for findability.

**AI**  
Morning briefings, anomaly callouts, “what needs attention now” — explainable.

**Actions**  
Rebalance, escalate structurally, tune policies, pause/resume automations.

**Audit**  
Operational interventions are recorded like customer interventions.

**Future vision**  
The ops lead’s command surface — complementary to CEO Mode’s owner surface.

---

### Finance360 (operational only)

**Purpose**  
Operational money context: payments received, refunds in flight, settlement/communication state — enough to serve customers and owners without becoming accounting software.

**Health**  
Stuck refunds, payment mismatches, missing confirmations, reconciliation exceptions that block operations.

**Timeline**  
Payment, refund, confirmation, and related customer communications.

**Relationships**  
Customer360, Work360, external payment systems of record.

**AI**  
Exception detection and next-step suggestions; no silent financial mutation.

**Actions**  
Approved operational finance actions (e.g. refund communications, escalation) under policy.

**Audit**  
Financially sensitive actions are highest-assurance audit citizens.

**Future vision**  
Owners see money-friction that hurts customers — while the ledger stays in accounting systems.

---

### Vendor360

**Purpose**  
Operational relationships with vendors and partners who affect delivery: logistics, repair, channel, telephony, messaging providers.

**Health**  
Dependency reliability, SLA of integrations, failure bursts, cost-of-failure signals (operational, not full procurement).

**Timeline**  
Incidents, sync failures, escalations to vendor, recoveries.

**Relationships**  
Asset360, Work360, Operations360, Notification/Outbox health.

**AI**  
Dependency risk summaries and recommended fallbacks.

**Actions**  
Retry, escalate, switch playbooks, communicate impact to customers.

**Audit**  
Vendor-impacting operational decisions and integration outcomes.

**Future vision**  
Partners are visible in the operational graph instead of hidden inside logs.

---

## Part 4 — Platform Engines

Platform Engines are **reusable capability layers**. They are stable contracts. Business modules plug into them.

Validated foundation: Platform Engine Architecture Review **P17-07-001**.

Governing rule:

> **Write primitive → Audit event → Timeline projection → (future) Search index**

Domain services decide *what* happened. Engines decide *how* shared platform concerns are recorded, shown, sent, assigned, found, and reasoned about.

---

### Timeline Engine

**Purpose**  
Project the operational story for a 360 object as one coherent history.

**Responsibilities**  
Merge event sources, deduplicate, group, paginate, and present timeline view-models. Timeline is a **read model**, not a second write store.

**Public entry point**  
Timeline composition service used by 360 surfaces (unified timeline framework).

**Extension model**  
New `TimelineEventSource` (or equivalent plugin) per domain of events. Prefer projecting from audit and domain tables over inventing parallel event stores.

**What it must NEVER own**  
Business write rules, channel sending, assignment policy, or module-specific card UX that cannot generalize.

**Future roadmap**  
Unify legacy activity timelines into the one framework; register workforce and work events; presentation policies instead of hard-coded hide/show rules.

---

### Audit Engine

**Purpose**  
Be the system of record for “who did what, when, to what, and with what evidence.”

**Responsibilities**  
Universal write primitive for auditable actions; structured payloads; morph/ownership to business objects; feed timelines, metrics, and compliance views.

**Public entry point**  
Central audit write API, with domain `*AuditService` wrappers for named events.

**Extension model**  
Named, namespaced audit events (registry/enum direction); thin wrappers per domain; aggregators for operations metrics.

**What it must NEVER own**  
UI layout, notification delivery, or domain eligibility rules.

**Future roadmap**  
Formal audit event registry before large Workforce event growth; fewer free-form colliding event names; richer read models without duplicate query logic.

---

### Notification Engine

**Purpose**  
Deliver the right message to the right recipient on the right channel — with a single authority path over time.

**Responsibilities**  
Multi-channel orchestration, recipient resolution, dispatch audit, and (via authority) prevention of parallel “mystery send” paths.

**Public entry point**  
Notification dispatcher / authority gate for outbound platform sends.

**Extension model**  
Channel plugins; authority-owned routing for agent and customer notifications; async paths via Outbox where reliability matters.

**What it must NEVER own**  
Long-lived business eligibility (beyond generic gates), template business content ownership, or inventing a fifth parallel stack for a new module.

**Future roadmap**  
Migrate remaining stacks behind authority; retire stub channels that fake success; unify agent bell and operational messaging conceptually under one governance model.

---

### Automation Engine

*(Product name for the extensible action/automation layer; historically Communication Actions plus Automation Runtime.)*

**Purpose**  
Execute approved, config-driven operational actions and workflows with eligibility, variables, lifecycle, and audit — including customer communications and broader workforce/ops automations over time.

**Responsibilities**  
Registry of actions, eligibility, variable resolution, execution, lifecycle (opened → sent → completed), and idempotent workflow runtime where needed.

**Public entry point**  
Action executor / automation runtime invoked by UI, schedules, or domain triggers.

**Extension model**  
Config registry entries + eligibility/support classes; target providers; lifecycle projected to timeline when dedupe rules are clear.

**What it must NEVER own**  
One-off bypass sends that skip lifecycle (except rare, documented domain exceptions on a path to retirement); unbounded custom scripts without registry.

**Future roadmap**  
All outbound operational actions prefer this engine; shared contact/eligibility helpers; workforce actions as first-class registry citizens.

---

### Assignment Engine

**Purpose**  
Decide and record **who owns the work**, under policy, with full history.

**Responsibilities**  
Canonical assignment write path; eligibility and workforce authority; smart/round-robin/strategy selection; post-assignment notifications via Notification Engine; audit-backed history.

**Public entry point**  
Canonical `applyAssignment`-style write path (one mutation door).

**Extension model**  
Strategy plugins (hardware routing, smart scoring, shift-aware pools); shared candidate pool; workforce scheduling eligibility via Workforce Authority.

**What it must NEVER own**  
Channel template content; timeline rendering; unrelated domain workflows (escalation product rules stay in domain services that *call* assignment).

**Future roadmap**  
Extract shared candidate pools; keep service-case specifics in domain services; Workforce Ops extends strategies instead of forking assignment.

---

### Search Engine

**Purpose**  
Help humans find the right operational object quickly — with one semantic approach.

**Responsibilities**  
Permission-aware global search; provider aggregation; tokenized relevance for registered entities.

**Public entry point**  
Global search façade used by universal search UI and, over time, other quick-find surfaces.

**Extension model**  
`GlobalSearchProvider` per entity family (work, customers, workforce, …).

**What it must NEVER own**  
Dashboard-only duplicate query languages that silently diverge; write-side business rules.

**Future roadmap**  
Workforce and Work providers; align in-memory quick filters with universal semantics; eventual index-on-write if scale demands it.

---

### AI Engine

**Purpose**  
Turn operational context into assistive intelligence: summaries, scores, recommendations, and drafts.

**Responsibilities**  
Canonical bundle/pipeline construction; pluggable providers; confidence and risk explanation; separation of “assist” from “decide.”

**Public entry point**  
AI bundle builder used by 360 AI surfaces and ops briefings (converging abstractions over time).

**Extension model**  
Context builders per scope (incident/customer today; team/queue/shift tomorrow); shared Knowledge Engine input; provider interface for model/rule backends.

**What it must NEVER own**  
Silent irreversible actions; duplicate business rules that already live in domain services; a second AI platform per module.

**Future roadmap**  
Unified reasoning provider abstraction; WorkforceContextBuilder pattern; less duplicated snapshot assembly in callers.

---

### Knowledge Engine

**Purpose**  
Aggregate and shape institutional knowledge for UI and AI — the facts and playbooks intelligence stands on.

**Responsibilities**  
Knowledge aggregation, mapping into AI/intelligence DTOs, freshness and domain packaging.

**Public entry point**  
Knowledge aggregation API used by AI and knowledge-facing UI.

**Extension model**  
New knowledge domains (workforce, device, finance-operational) registered into the engine rather than scraped ad hoc in each feature.

**What it must NEVER own**  
Final customer-facing send; assignment writes; audit storage.

**Future roadmap**  
Stronger feedback loop from Work360 outcomes; clearer ownership of curated vs inferred knowledge.

---

### Workspace Engine

**Purpose**  
Provide the interaction contract for doing work in-place: modals, fragments, actions, and refresh — One Workspace in software form.

**Responsibilities**  
Workspace components, action responses, refresh policy, consistent operator surfaces across 360 objects.

**Public entry point**  
Workspace component / action response layer used by product UI.

**Extension model**  
New actions and fragments that honor the refresh and response contract; presentation variants, not parallel modal systems.

**What it must NEVER own**  
Deep domain validation that belongs in domain services; a second “mini app” navigation model per module.

**Future roadmap**  
Same workspace contract for Workforce and Work360 surfaces; fewer dual action surfaces on the same object.

---

### Outbox Engine

**Purpose**  
Reliably execute side effects that must not be lost: retries, backoff, and at-least-once processing for integrations.

**Responsibilities**  
Transactional outbox processing for async work (e.g. messaging), generic enough for other side effects.

**Public entry point**  
Outbox processor invoked by the async runtime.

**Extension model**  
New outbox event types + processors; keep payload contracts versioned and auditable.

**What it must NEVER own**  
Product eligibility narrative; UI; inventing business outcomes without domain confirmation.

**Future roadmap**  
Broader use for any critical side effect Workforce and Operations need to trust.

---

### Policy Engine *(future architecture)*

**Purpose**  
Make operational rules explicit, testable, and explainable: who may do what, when automation may fire, what exceptions apply.

**Responsibilities** *(target)*  
Evaluate policies for eligibility, authority, automation gates, and owner-configurable guardrails; return explainable allow/deny/reason.

**Public entry point** *(target)*  
`PolicyEngine::evaluate(decision, context)` (name illustrative).

**Extension model**  
Declarative policies + domain policy packs; versioning; simulation (“what would this policy do?”).

**What it must NEVER own**  
Message sending, timeline storage, or embedding irrecoverable business secrets in scattered `if` statements across modules.

**Future roadmap**  
Extract repeated eligibility and authority checks (assignment, automation, communication, workforce) into policy packs; keep engines calling Policy, not re-implementing it.

---

## Part 5 — Business Objects vs Platform Engines

Radium Desk has a strict layering model:

```text
Business Objects (360s)
        ↓
Domain Services (module business rules)
        ↓
Platform Engines (shared capability contracts)
        ↓
Infrastructure (database, queues, external APIs, realtime)
```

### What each layer owns

| Layer | Owns | Does not own |
|-------|------|--------------|
| **Business Objects** | Meaning, health, relationships, owner-facing narrative | Transport, storage engines |
| **Domain Services** | Business rules, eligibility specifics, workflow choices | Generic timeline merge, generic outbox retry |
| **Platform Engines** | Reusable write/read/extension contracts | Product-specific policy exceptions as hard forks |
| **Infrastructure** | Durability, transport, vendor SDKs | “Why we refund” or “who should own this case” |

### Non-negotiable rule

**Business rules must never leak into Platform Engines.**

Engines may enforce *generic* gates (e.g. “has a reachable contact,” “user authenticated,” “idempotency key present”).  
They must not encode *product* rules (e.g. “RDE hardware orders route to team X”) — those stay in domain services that *call* engines.

### Correct extension pattern

1. Identify the **360 object** that owns the concept.  
2. Implement rules in a **domain service**.  
3. Persist truth via **Audit** (and domain tables as needed).  
4. Project history via **Timeline** sources.  
5. Notify via **Notification / Automation**.  
6. Assign via **Assignment**.  
7. Make findable via **Search**.  
8. Inform judgment via **Knowledge + AI**.

If a proposal skips to “new table + new UI + new notifier,” it is usually wrong.

---

## Part 6 — Development Standards

Every new feature must answer these questions before design is approved:

1. **Which operational problem does this solve?**  
   Name the friction in the real business, not the ticket number.

2. **Which business object owns this?**  
   Customer360, Work360, Workforce360, Asset360, Knowledge360, Operations360, Finance360, or Vendor360.

3. **Which platform engine can it reuse?**  
   Timeline, Audit, Notification, Automation, Assignment, Search, AI, Knowledge, Workspace, Outbox, (future) Policy.

4. **Is this business logic or platform capability?**  
   If both, split them. Rules in domain; capability in engine.

5. **Will this still make sense with 10,000 customers?**  
   Reject designs that only work for today’s volume or today’s one team.

6. **Can the owner understand this from a mobile phone in under 60 seconds?**  
   If the feature is owner-relevant and fails this test, redesign the information hierarchy.

Features that cannot answer these clearly are not ready to build.

---

## Part 7 — Architecture Roadmap

Technical debt is reduced **incrementally while delivering business value**. No big-bang rewrites.

### Phase 0 — Architecture Constitution

- Publish and adopt this handbook  
- Align product and engineering language: 360 objects + platform engines  
- Use the Product Test (Part 9) and Development Standards (Part 6) in reviews

### Phase 1 — Platform stabilization

Stabilize without big-bang refactoring:

- Formalize audit event naming/registry direction  
- Prefer unified timeline projection for new events  
- Wire notification authority before new alert paths proliferate  
- Extract shared assignment candidate-pool concepts before Workforce scheduling couples to service-case internals  
- Prove Search extensibility with new providers  
- Consolidate AI context building patterns under the AI Engine

Deliver customer-visible value in parallel; pay down fragmentation as part of those deliveries.

### Phase 2 — Workforce Operations

- Workforce360 becomes real: schedules, presence, capacity, fair assignment  
- Extend Timeline, Audit, Notification, Assignment, Search, AI — do not fork them  
- Policy thinking begins wherever eligibility repeats

### Phase 3 — Work360

- Unify the mental model of “work” beyond a single incident screen  
- Same engines; clearer object boundaries; stronger ownership and SLA narratives

### Phase 4 — Operations Intelligence

- Operations360 matures: bottlenecks, automation health, explainable priorities  
- Knowledge compounding from outcomes  
- AI briefings that leaders trust because every score explains itself

### Phase 5 — CEO Mode

- Owner-grade mobile-first visibility across 360 health  
- “What needs me?” in under 60 seconds  
- Automation and AI handle repetition; humans handle judgment and relationships

---

## Part 8 — The Mobile First Principle

### Permanent design rule

> **The owner should be able to understand the health of the business from a mobile phone in less than 60 seconds.**

This is not a styling preference. It is a product constraint.

### Implications

- Every dashboard and major feature must declare its **60-second owner story** (even if the primary user is an agent).  
- Hierarchy beats density: health → exceptions → next actions.  
- Scores without explanations fail the principle.  
- Module hopping fails the principle.  
- Desktop-only sprawl that cannot collapse to a clear mobile narrative fails the principle.

Agents may need deep workspaces. Owners need an honest pulse. Both must be designed on purpose.

---

## Part 9 — The Product Test

Every proposed feature should improve **at least one** of:

| Pillar | Question |
|--------|----------|
| **Customer Experience** | Does the customer get a faster, clearer, fairer outcome? |
| **Employee Efficiency** | Does the team spend less time on repetitive or confusing work? |
| **Owner Visibility** | Does the owner see truth sooner — especially on mobile? |

If it improves **none** of these, challenge whether it belongs in Radium Desk.

If it improves one pillar by harming another (e.g. owner vanity metrics that increase agent toil with no customer benefit), redesign until the tradeoff is explicit and acceptable.

---

## Appendix A — How to use this document

| Role | Use it to… |
|------|------------|
| **Product Manager** | Shape roadmap against 360 objects and the Product Test |
| **Architect** | Keep engines stable; reject business-rule leakage |
| **Developer** | Answer the six Development Standards questions in PRs/designs |
| **New team member** | Learn *why* before *where the files are* |

Related reading:

| Document | Role |
|----------|------|
| [Product Constitution](product-constitution.md) | Original agent-workspace principles (One Workspace / Timeline / Truth) |
| [Master Architecture](radium-desk-v2-master-architecture.md) | System orientation and cross-links |
| Platform Engine Review P17-07-001 | Validated engine inventory, debt, and extension points |
| Engine-specific docs (timeline, notification authority, workspace, …) | Implementation detail when building |

---

## Appendix B — Constitution pledge

We build Radium Desk so that:

1. **Operations are visible** — owners and leads are never guessing in the dark.  
2. **Repetition is automated** — teams spend time on judgment and care.  
3. **AI is accountable** — assistance is explainable; humans decide.  
4. **Engines stay shared** — modules plug in; they do not fork the platform.  
5. **Truth stays singular** — orchestration never becomes a second conflicting record.  
6. **Simplicity wins** — especially in sixty seconds on a phone.

---

## Document History

| Version | Date | Notes |
|---------|------|-------|
| 1.0 | 2026-07-17 | Initial Operations Intelligence Platform Constitution; founded on P17-07-001 Platform Engine review |
