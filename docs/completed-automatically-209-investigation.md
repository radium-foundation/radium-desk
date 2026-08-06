# Completed Automatically — 209 “Suspicious” Subjects

**Date:** 2026-08-06  
**Type:** Production read-only investigation  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/completed-automatically-209.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/completed-automatically-209.canvas.tsx)  
**Related:** [ira-learning-center-ux-redesign.md](./ira-learning-center-ux-redesign.md)

---

## Question

Are the **209** Completed Automatically emails whose subjects contain `help` / `issue` / `problem` / `complaint` wrongly completed customer support mail?

## Population

Ignored mail in the Completed Automatically set (`ignore_reason` in `auto_responder`, `bounce_or_delivery_subsystem`, `known_system_email`, `own_outbound`, or classification `own_outbound` / disposition `auto_processed`).

Keyword scan limited to `ignore_reason = known_system_email`.

## Findings

| Metric | Count |
|--------|------:|
| Completed Automatically total | **7,686** |
| known_system_email | 6,275 |
| auto_responder | 1,360 |
| own_outbound | 44 |
| bounce_or_delivery_subsystem | 7 |
| Duplicate-subject row sum | 4,953 |
| Keyword “suspicious” (`help`/`issue`/`problem`/`complaint`) | **209** |
| Remaining after noreply/system false-positive filters | **0** |

### Who sent the 209

| Sender | Count | Nature |
|--------|------:|--------|
| `donotreply@amazon.com` | 170 | ASIN quality / “customer complaints” seller alerts |
| `noreply@flipkart.com` (+1 variant) | 21 | Marketing / webinar / “help you grow” |
| `noreply@amazon.com` | 8 | Ads / “help control your budget” |
| `noreply@gem.gov.in` | 7 | GeM bid corrigendum (“issue”) |
| Google noreply | 3 | Security / GMB |

### Top subjects in the 209

| Subject (abbrev.) | Count |
|-------------------|------:|
| Warning: ASIN(s) … high customer **complaints** related to product quality | 160 |
| Warning: offer on ASIN(s) with high customer **complaints**… | 10 |
| Sponsored Brands can **help** showcase… | 3 |
| GeM corrigendum (**issue**d) | 3+ |
| Flipkart / Google “**help** …” marketing | rest |

## Verdict

**No misroutes.** The 209 are marketplace/vendor **System Notifications** (and often **Duplicate Notifications**) whose subjects happen to contain support-ish English words. They belong in Completed Automatically.

No routing, filter, or ignore-reason changes recommended from this scan.

## Operator follow-up shipped with this work

1. Completed Automatically sub-groups so operators can inspect System Notifications / Duplicates separately.  
2. **Review Suggested** queue for mail where IRA explicitly recorded low confidence (`ira_confidence < 45`) or processing failed — presentation only; Needs Human and routing unchanged.

---

## Method (reproducible)

```sql
SELECT COUNT(*) FROM incoming_email_messages
WHERE status = 'ignored'
  AND ignore_reason = 'known_system_email'
  AND (
    subject LIKE '%help%' OR subject LIKE '%issue%'
    OR subject LIKE '%problem%' OR subject LIKE '%complaint%'
  );
```
