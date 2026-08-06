# Email Intake Phase 1 — Production Audit

**Date:** 2026-08-06  
**Scope:** Compact KPI hover polish + read-only production verification of last 100 incoming emails  
**Environment:** Production (`desk.radiumbox.com`) via SSH + `php artisan tinker`  
**Sample:** Message IDs `179165`–`179264` (received ~2026-08-05 15:22 → 2026-08-06 11:56 IST)  
**Canvas:** [`email-intake-phase1-production-audit.canvas.tsx`](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/email-intake-phase1-production-audit.canvas.tsx)

**No workflow / routing / Learning Center logic changes in this pass** (hover CSS only).

---

## Executive verdict

Email Intake Phase 1 **sync + auto-processing is healthy** for current mail:

| Signal | Result |
|--------|--------|
| Last 100 synced | **100 / 100** |
| Last 100 processed (`processed_at` set) | **100 / 100** |
| Failures in sample | **0** |
| Needs Human in sample | **0** |
| Permanent Needs Human backlog (all-time open) | **3** (unchanged since 2026-08-03) |

Pipeline is advancing. The dashboard “3 Needs Attention” is a **real, untouched/incomplete Learning Center disposition backlog**, not a sync freeze.

**Do not declare Phase 1 complete** until the Learning Center disposition gap is fixed: **Assign** and non-ignore **Classification** (including **Docs**) leave messages in `needs_review` forever.

---

## Task 1 — Compact KPI hover

### Change

Visual-only tightening of `.dashboard-email-intake-kpi__hover*` in `resources/css/app.css` to match Learning Center density.

| Property | Before | After |
|----------|--------|-------|
| Hover text | ~11px (`0.6875rem`) | **13px** |
| Row layout | flex baseline | **grid** `1fr auto` (label / count aligned) |
| Row line-height | 1.4 | **1.25** |
| List row gap | none | **0.05rem** |
| Title margin | `0.375rem` | **0.2rem** |
| Divider margin | `0.375rem 0` | **0.2rem 0** |
| Card padding | `0.625rem 0.75rem` | **0.4rem 0.55rem** |
| Min width | `12rem` | **10.5rem** |
| Radius / offset | larger | slightly tighter |

### Unchanged

- Widget data / counters / severity colors  
- Hover row labels (Sales / Orders / Escalations / Promotions / Spam / Completed Automatically)  
- Blade markup structure (class names preserved for DOM patching tests)

### Deploy note

CSS ships with Vite build. Local change is in repo; production will pick it up on next asset deploy (`deskd` / build sync).

---

## Task 2 — Last 100 email audit summary

Observed production flags at audit time:

| Flag | Value |
|------|-------|
| Smart routing | **off** |
| Auto-create service case | **on** |
| Needs Human count (all open) | **3** |
| Dashboard Needs Attention | **3** (Sales 2 / Orders 1 / Escalations 0) |

### Roll-up (requested format)

| Metric | Count |
|--------|------:|
| Synced | **100** |
| Linked Existing Case | **59** |
| New Service Cases | **3** |
| Refund Cases (emails classified refund + linked) | **6** (2 distinct cases) |
| Sales Cases (new) | **3** |
| Ignored | **38** |
| Promotion (promo + newsletter) | **13** |
| Spam | **5** |
| Completed Automatically (linked + non-promo/spam ignores) | **82** |
| Needs Human (in sample) | **0** |
| Failures | **0** |

### Status / classification breakdown

| Status | Count |
|--------|------:|
| `linked` | 62 |
| `ignored` | 38 |
| `needs_review` / `failed` / unprocessed | 0 |

| Classification | Count | Typical outcome |
|----------------|------:|-----------------|
| `existing_customer` | 42 | Linked to existing SC |
| `vendor_action` | 10 | Linked (RD Service Online / Shopify threads) |
| `refund` | 6 | Linked to existing refund SCs |
| `possible_sales_lead` | 3 | **New** SC auto-created |
| `docs` | 1 | Linked via learning rule `#2` |
| `promotional` | 7 | Ignored |
| `newsletter` | 6 | Ignored |
| `spam` | 5 | Ignored |
| `other_ignored` | 19 | Ignored (`known_system_email` / auto_responder) |
| `own_outbound` | 1 | Ignored |

### Ignore reasons

| Reason | Count |
|--------|------:|
| `known_system_email` | 18 |
| `promotions` | 7 |
| `newsletter_or_marketing` | 6 |
| `spam` | 5 |
| `own_outbound` | 1 |
| `auto_responder` | 1 |

### Learning rules used in sample

Only **1 / 100** messages had `matched_learning_rule_id` set:

| Rule | Match | Decision | Message |
|------|-------|----------|---------|
| `#2` | subject `order confirmed` | classification → `docs` | `179246` → linked `SC24877` |

Three learning rules exist in production (all taught 2026-08-06 while triaging the permanent backlog); rules `#1` and `#3` have `times_used = 0` because they only annotated the already-pending messages.

---

## Processing accuracy

| Check | Verdict |
|-------|---------|
| Sync completeness | **Pass** — all 100 present with provider IDs / `received_at` |
| Processor completion | **Pass** — all have `processed_at`, none stuck `received`/`processing` |
| Failure rate | **Pass** — 0 `failed`, 0 `processing_error` |
| Noise filtering | **Pass** — Amazon seller system mail, GeM, Google, newsletters, spam correctly ignored |
| Existing-customer linking | **Pass** — 42 `existing_customer` all linked with `order_id` + `incident_id` |
| Vendor thread linking | **Pass** — RD Service Online + Shopify confirmations attach to known cases |
| Refund customer mail | **Pass** — customer refund threads link to `SC26649` / `SC26926` |
| Sales auto-create | **Mixed** — 2/3 look like real unknown-customer work; **1 false positive** (Naukri survey → `SC27214`) |

### Case creation accuracy

New cases in sample (auto-create on, smart routing off):

| Msg | From | Subject | Case | Assessment |
|----:|------|---------|------|------------|
| 179263 | safanashaik7@gmail.com | Update device | **SC27794** | Plausible unknown-customer / sales intake |
| 179252 | aptitudeedutechskills@gmail.com | Fwd: Your Order at RD SERVICE ONLINE is successful | **SC27689** | Plausible (forwarded order confirmation, no prior match) |
| 179190 | naukritalentcloud@naukri.com | Naukri’s Hiring Outlook Survey… | **SC27214** | **Incorrect** — survey/marketing should ignore, not create Sales SC |

### Refund routing

- Customer-facing refund threads (**6** emails) → linked to existing cashfree-origin cases `SC26649`, `SC26926`. Correct.  
- Amazon `donotreply@amazon.com` “INR refund initiated” seller notifications → **ignored** as `known_system_email`. Correct (not customer refund intake).

### Sales routing

- Classifier marks unmatched customer-ish mail as `possible_sales_lead` → auto-create SC.  
- Works for real unknowns; over-fires on survey/HR vendor mail (Naukri).  
- Permanent backlog Sales bucket still holds Amazon Andon + Aditya (see below).

### Existing customer linking

- **59 / 62** linked emails attached to a case that already existed before the message.  
- Thread replies from `mail@radiumbox.com` correctly re-link to the customer case.  
- Closed-case reopen path exercised in sample (`179166` “Test Case reopen” → `SC03738`).

### Unknown customer handling

- In the last 100: **0** remained `unknown_customer` / Needs Human — auto-create absorbed unmatched operational mail.  
- Outside the sample, **3** older unknowns remain Needs Human because they were parked before auto-create / never reprocessed, and Learning Center Assign/Docs did not clear them.

---

## The three permanent Needs Human emails

Still open as of 2026-08-06 ~12:04 IST. Dashboard hover: Sales **2**, Orders **1**.

| ID | Received (IST) | From | Subject | Class now | Ignore reason | Learning applied |
|---:|----------------|------|---------|------------|---------------|------------------|
| **178723** | 2026-08-03 20:13 | amazon-in-andon@amazon.in | `[CASE …] Reminder: Urgent… Andon Cord` | `possible_sales_lead` | `unknown_customer` | Assign → Dileep Sen (rule `#3`, 10:05) |
| **178727** | 2026-08-03 21:05 | store+23635731@t.shopifyemail.com | `Order confirmed` | `docs` | `unknown_customer` | Classification → Docs (rule `#2`, 10:04) |
| **178731** | 2026-08-03 21:32 | aditya.sharma2307@gmail.com | `Regarding RD Service` | `possible_sales_lead` | `unknown_customer` | Assign → Shubhanshi Rathore (rule `#1`, 09:29) |

All three: `status = needs_review`, `incident_id = null`, `order_id = null`.

### Why still pending

1. Original process (2026-08-03): no order/customer match → `markNeedsReview(unknown_customer)` (auto-create was not applied to these rows at that time; they were never re-ingested).  
2. Operators **did** teach IRA on 2026-08-06 (Assign / Docs).  
3. Those Learning Center actions only update IRA fields + save rules — they **do not**:
   - create / link a service case  
   - change `status` away from `needs_review` (except Promotion / Spam / Completed Automatically / Ignore)  
   - re-run the processor / auto-create path  

So the tile stays at 3 even after “successful” teaching.

### Expected operator action today (workaround)

| Email | Practical disposition | Why |
|-------|----------------------|-----|
| 178723 Amazon Andon | **Ignore** (System Email / Always Ignore domain) or classify **Vendor** then Ignore | Marketplace ops ping, not a customer SC |
| 178727 Shopify Order confirmed | **Ignore once** or Promotion/Completed Automatically — **Docs alone will not clear** | Later Shopify “Order confirmed” mail already auto-links when an order match exists; this orphan has none |
| 178731 Aditya Sharma | Manually **create/link SC** (or Ignore if duplicate), then clear queue | Real customer prose (“purchased Device… 2 year RD”); **no order** found by `customer_email` in production |

### Why they never leave Needs Human

| Action used | Clears Needs Human? | Creates case? |
|-------------|---------------------|---------------|
| Assign | **No** | **No** |
| Classification: Support / Sales / Refund / Vendor / **Docs** | **No** | **No** |
| Classification: Promotion / Spam / Completed Automatically | Yes → `ignored` | No |
| Ignore (once / always / …) | Yes → `ignored` | No |

There is **no Learning Center action** that means “accept as work item / create case / open C360 / mark done after assign.”

### Workflow incomplete?

**Yes — disposition pipeline is incomplete for Phase 1 “teach + clear.”**

- Teaching Assign/Docs updates explainability and future rules, but **does not complete the human queue item**.  
- Docs is especially confusing: later Shopify mail with rule `#2` **links** when matcher finds an order, but the orphan Docs-classified message stays Needs Human forever.  
- Assign suggests an owner but never notifies/assigns a case because no case exists.

### Recommended UX improvements (do not implement yet)

1. **Assign must complete the item** — after assignee chosen: either auto-create SC + assign + link + leave Needs Human, or move to an “Assigned / Waiting case” state that is not counted as Needs Attention.  
2. **Docs / Vendor classifications should park or ignore** (leave Needs Human) when no case is created — same family as Completed Automatically for queue purposes, or offer “Docs → Ignore + learn”.  
3. **Primary CTA on Needs Human card:** “Create case” / “Link to case” / “Ignore” — teaching scope secondary.  
4. **Show blocker explicitly:** “Stuck because Assign does not create a case” instead of looking “done” with confidence 100.  
5. **One-shot reprocess** after teaching operational classifications (Support/Sales/Refund) so auto-create can run with the new class.  
6. **Sales auto-create guardrails** — block known survey/HR domains (e.g. `naukri.com`) to prevent `SC27214`-class false positives.  
7. Ops cleanup now: disposition the 3 rows manually so the tile returns to 0 without code.

---

## Accuracy scorecard (Phase 1)

| Area | Score | Notes |
|------|-------|-------|
| Sync | Excellent | 100% in sample |
| Auto ignore noise | Excellent | System / promo / spam |
| Existing customer link | Excellent | 59 linked-existing |
| Refund customer routing | Excellent | Linked to right SCs |
| Vendor confirmations | Good | Linked; one orphan Shopify stuck from Aug 3 |
| Sales / unknown auto-create | Fair | Works, 1 clear false positive in 3 |
| Learning Center clear-out | **Poor** | Assign/Docs leave permanent Needs Human |
| End-to-end “Phase 1 complete” | **Not yet** | Fix disposition gap + clear 3 + tighten sales false positives |

---

## Recommended before declaring Phase 1 complete

1. Review this audit (no workflow code until approved).  
2. Design Learning Center completion semantics for Assign + Docs/Vendor/Support/Sales/Refund.  
3. Add sales auto-create denylist / stronger promo detection for survey senders.  
4. Ops: clear ids `178723`, `178727`, `178731`.  
5. Re-verify: Needs Attention = 0 after disposition; sample of next 50 emails has no permanent park without explicit Ignore.  
6. Ship compact hover CSS with next deploy.  
7. Only then call Email Intake Phase 1 complete.

---

## Appendix A — Last 100 emails (compact)

| ID | Status | Class | Case | Learning | From | Subject |
|---:|---|---|---|---|---|---|
| 179264 | linked | existing_customer | SC26352 | — | jansevabasera@gmail.com | rd service recharge done 4 august 2026 but not upd |
| 179263 | linked | possible_sales_lead | SC27794 | — | safanashaik7@gmail.com | Update device |
| 179262 | ignored | spam | — | — | shubham.varma@ecoprocesssolutions.com | Introducing Economy Process Solutions for Vacuum S |
| 179261 | linked | existing_customer | SC26580 | — | chellasamypandian6399@gmail.com | Photo from Chellasamy |
| 179260 | ignored | other_ignored | — | — | families-noreply@google.com | Join rajesh's family group? |
| 179259 | linked | existing_customer | SC25559 | — | principalhsskhellani@gmail.com | Re: subject: RD Service Recharge Not Activated – E |
| 179258 | linked | existing_customer | SC24924 | — | bidyadharkharsel899@gmail.com | Re: Receved Defective Blacklisted device |
| 179257 | ignored | other_ignored | — | — | donotreply@amazon.com | 2695 INR refund initiated - order 402-6474614-3537 |
| 179256 | ignored | other_ignored | — | — | donotreply@amazon.com | 3695 INR refund initiated - order 171-1491263-2601 |
| 179255 | linked | existing_customer | SC22973 | — | nagababu8978@gmail.com | Hai Sir RD3469054 Plz Activate RD Service |
| 179254 | linked | existing_customer | SC27619 | — | dileepmobilecentre123@gmail.com | Fwd: Radium Box Order Confirmation |
| 179253 | ignored | own_outbound | — | — | mail@radiumbox.com | Fwd: Mantra MFS110 RD service activation |
| 179252 | linked | possible_sales_lead | SC27689 | — | aptitudeedutechskills@gmail.com | Fwd: Your Order at RD SERVICE ONLINE is successful |
| 179251 | ignored | newsletter | — | — | research@crisilinfo.com | Crisil Ratings webinar on the shrimp sector: Casti |
| 179250 | linked | existing_customer | SC25782 | — | shatellyprashanth@gmail.com | Mantra L1 Not working Please solve the issue |
| 179249 | linked | vendor_action | SC25699 | — | admin@rdserviceonline.com | Order #3439407 confirmed |
| 179248 | linked | existing_customer | SC27619 | — | dileepmobilecentre123@gmail.com | Fwd: Radium Box Order Confirmation |
| 179247 | linked | existing_customer | SC26580 | — | chellasamypandian6399@gmail.com | Re: Radium Box Order Confirmation |
| 179246 | linked | docs | SC24877 | #2 | store+23635731@t.shopifyemail.com | Order confirmed |
| 179245 | linked | existing_customer | SC24924 | — | vrkannanram@gmail.com | Re: RD SERVICE NOT WORKING |
| 179244 | linked | existing_customer | SC24924 | — | mail@radiumbox.com | Re: Resolution- RD3395302… |
| 179243 | ignored | newsletter | — | — | naman@mail.internshala.com | Update: Complimentary Job Post… |
| 179242 | linked | existing_customer | SC27403 | — | adfdoda@gmail.com | Re: Help Us Complete Your Device Setup |
| 179241 | ignored | promotional | — | — | dhivya@relativity.co.in | Save the Date: Webinar… |
| 179240 | linked | existing_customer | SC22377 | — | suchithmsuchi@gmail.com | Re: Delay in delivery |
| 179239 | linked | existing_customer | SC26627 | — | mail@radiumbox.com | Re: The RD service is not working. |
| 179238 | linked | existing_customer | SC26674 | — | mail@radiumbox.com | Re: Mantra divice renival |
| 179237 | linked | existing_customer | SC26883 | — | mail@radiumbox.com | Re: Request for SDK for Morpho 1300 E3 RD |
| 179236 | linked | existing_customer | SC26883 | — | mail@radiumbox.com | Re: Request for SDK for Morpho 1300 E3 RD |
| 179235 | linked | existing_customer | SC27350 | — | mmsstudio251415@gmail.com | this recharge amount for what purpose |
| 179234 | linked | existing_customer | SC26557 | — | mail@radiumbox.com | Re: |
| 179233 | linked | existing_customer | SC25404 | — | mail@radiumbox.com | Re: Radium Box Order Confirmation |
| 179232 | linked | refund | SC26649 | — | mail@radiumbox.com | Re: Urgent: Refund Request… RD3474705 |
| 179231 | linked | refund | SC26649 | — | mail@radiumbox.com | Re: Urgent: Refund Request… RD3474705 |
| 179230 | linked | existing_customer | SC27350 | — | mmsstudio251415@gmail.com | Recharge success device not connected |
| 179229 | linked | existing_customer | SC22501 | — | mail@radiumbox.com | Re: Grivances |
| 179228 | linked | existing_customer | SC27155 | — | mail@radiumbox.com | Re: |
| 179227 | linked | existing_customer | SC07573 | — | mail@radiumbox.com | Re: All Orders History Required |
| 179226 | linked | existing_customer | SC22377 | — | mail@radiumbox.com | Re: Delay in delivery |
| 179225 | linked | existing_customer | SC25404 | — | bittucyber3@gmail.com | Re: Radium Box Order Confirmation |
| 179224 | linked | existing_customer | SC25404 | — | bittucyber3@gmail.com | Re: Radium Box Order Confirmation |
| 179223 | ignored | other_ignored | — | — | donotreply@amazon.com | FBA reimbursement notification |
| 179222 | ignored | promotional | — | — | pranjali.shirodkar@crisilinfo.com | Crisil Training Calendar - August 2026 |
| 179221 | ignored | newsletter | — | — | info@net.shiprocket.in | Undelivered Orders Report as of 06-08-2026 |
| 179220 | ignored | promotional | — | — | global-seller@email.alibaba.com | How a Delhi brand hit ₹12 crores… |
| 179219 | linked | vendor_action | SC25699 | — | admin@rdserviceonline.com | Order #3439245 confirmed |
| 179218 | linked | vendor_action | SC24877 | — | store+23635731@t.shopifyemail.com | Order confirmed |
| 179217 | linked | vendor_action | SC25699 | — | admin@rdserviceonline.com | Order #3439230 confirmed |
| 179216 | linked | existing_customer | SC26143 | — | bsbyghkekri@gmail.com | Re: Biomatric Device |
| 179215 | linked | vendor_action | SC24877 | — | store+23635731@t.shopifyemail.com | Order confirmed |
| 179214 | ignored | spam | — | — | newsletter@ettech.com | Impact of MDR’s return… |
| 179213 | ignored | other_ignored | — | — | donotreply@amazon.com | 3695 INR refund initiated… |
| 179212 | ignored | other_ignored | — | — | donotreply@amazon.com | 3561.55 INR refund initiated… |
| 179211 | ignored | other_ignored | — | — | newsletter@economictimesnews.com | ET AI: Google shakes up AI leadership… |
| 179210 | linked | existing_customer | SC24924 | — | jayu3855@yahoo.com | Re: Resolution- RD3395302… |
| 179209 | ignored | other_ignored | — | — | donotreply@amazon.com | 3561.55 INR refund initiated… |
| 179208 | ignored | newsletter | — | — | messages@cii.in | CII ESG & Climate Action Forum… |
| 179207 | ignored | other_ignored | — | — | donotreply@amazon.com | 2324 INR refund initiated… |
| 179206 | ignored | other_ignored | — | — | businessprofile-noreply@google.com | Radium Box, you got 5 new reviews |
| 179205 | ignored | other_ignored | — | — | donotreply@amazon.com | 2989 INR refund initiated… |
| 179204 | ignored | other_ignored | — | — | donotreply@amazon.com | 2749 INR refund initiated… |
| 179203 | ignored | other_ignored | — | — | donotreply@amazon.com | 4499 INR refund initiated… |
| 179202 | ignored | other_ignored | — | — | noreply@gem.gov.in | New bid(s) published on GeM portal. |
| 179201 | ignored | spam | — | — | teresa@mail.hardcomponentstech.com | Phoenix connector |
| 179200 | linked | refund | SC26649 | — | sainetcafe020@gmail.com | Urgent: Refund Request for Order ID RD3474705 |
| 179199 | ignored | newsletter | — | — | info@net.shiprocket.in | No action on NDR |
| 179198 | ignored | other_ignored | — | — | donotreply@amazon.com | 2850 INR refund initiated… |
| 179197 | linked | vendor_action | SC25699 | — | admin@rdserviceonline.com | Order #3439136 confirmed |
| 179196 | ignored | promotional | — | — | conference@conference.cii.in | 7th International Energy Conference… |
| 179195 | linked | vendor_action | SC24877 | — | store+23635731@t.shopifyemail.com | Order confirmed |
| 179194 | linked | existing_customer | SC22501 | — | infocacademy@gmail.com | Grivances |
| 179193 | ignored | spam | — | — | suhusuhana888@gmail.com | (empty) |
| 179192 | ignored | newsletter | — | — | info@net.shiprocket.in | Undelivered Orders Report as of 05-08-2026 |
| 179191 | ignored | other_ignored | — | — | donotreply@amazon.com | 3695 INR refund initiated… |
| 179190 | linked | possible_sales_lead | SC27214 | — | naukritalentcloud@naukri.com | Naukri’s Hiring Outlook Survey… |
| 179189 | ignored | spam | — | — | chrisevansbly@outlook.com | Re: Yes |
| 179188 | ignored | promotional | — | — | support@aioseo.com | Catch typos before your readers do |
| 179187 | linked | existing_customer | SC27155 | — | shahejaj@gmail.com | (empty) |
| 179186 | ignored | other_ignored | — | — | noreply@gem.gov.in | New bid(s) published on GeM portal. |
| 179185 | linked | existing_customer | SC07573 | — | nirajsen49@gmail.com | Re: All Orders History Required |
| 179184 | ignored | other_ignored | — | — | donotreply@amazon.com | 2689 INR refund initiated… |
| 179183 | linked | vendor_action | SC07573 | — | nirajsen49@gmail.com | All Orders History Required |
| 179182 | linked | existing_customer | SC27020 | — | mail@radiumbox.com | Re: RD L110 Recharge Issue |
| 179181 | linked | refund | SC26926 | — | mail@radiumbox.com | Re: Plz refund |
| 179180 | linked | refund | SC26926 | — | mail@radiumbox.com | Re: Plz refund |
| 179179 | linked | refund | SC26926 | — | mail@radiumbox.com | Re: Plz refund |
| 179178 | ignored | other_ignored | — | — | donotreply@amazon.com | 2695 INR refund initiated… |
| 179177 | linked | existing_customer | SC22377 | — | suchithmsuchi@gmail.com | Re: Delay in delivery |
| 179176 | linked | vendor_action | SC25699 | — | admin@rdserviceonline.com | Order #3438970 confirmed |
| 179175 | linked | vendor_action | SC24877 | — | store+23635731@t.shopifyemail.com | Order confirmed |
| 179174 | ignored | promotional | — | — | shipwithus@dtdc.com | Your Delivery Reflects Your Brand |
| 179173 | linked | existing_customer | SC22377 | — | mail@radiumbox.com | Re: Delay in delivery |
| 179172 | linked | existing_customer | SC26783 | — | mail@radiumbox.com | Re: Mantra l1 rd service not activate… |
| 179171 | linked | existing_customer | SC26783 | — | mail@radiumbox.com | Re: Mantra l1 rd service not activate… |
| 179170 | linked | existing_customer | SC22377 | — | mail@radiumbox.com | Re: Delay in delivery |
| 179169 | linked | existing_customer | SC26768 | — | mail@radiumbox.com | Re: Iris Scanner |
| 179168 | linked | existing_customer | SC26768 | — | mail@radiumbox.com | Re: Iris Scanner |
| 179167 | linked | existing_customer | SC26513 | — | mail@radiumbox.com | Re: Softwere |
| 179166 | linked | existing_customer | SC03738 | — | ravithelavi@gmail.com | Test Case reopen |
| 179165 | ignored | promotional | — | — | mail@info.paytm.com | 10,000 Gold Coins on Your Next Bill Payment!! |

---

## Appendix B — Per-email field map (how the 14 questions were answered)

For each of the last 100 rows:

1. **Synced?** Yes if row exists in `incoming_email_messages` (all 100).  
2. **Customer identified?** Yes when `order_id` / `incident_id` set after process (62 linked).  
3. **Existing / New / Unknown?** Existing = linked non-new case; New = auto-created SC in this process; Unknown = none in sample (3 outside sample).  
4. **IRA classification** = `classification` column.  
5. **Learning rule** = `matched_learning_rule_id` (+ rule table).  
6. **Service case created?** New if incident `created_at` ≈ message process time and source `email` (3). Else linked existing or N/A if ignored.  
7. **Linked existing?** Linked status with pre-existing incident (59).  
8. **Ignored?** `status=ignored` + `ignore_reason`.  
9–12. **Refund / Sales / Promotion / Spam?** From classification.  
13. **Completed Automatically?** Linked or ignored without human queue.  
14. **Needs Human?** `needs_review`/`failed` — 0 in sample; 3 global open analyzed above.

---

*Investigation only. Workflow changes deferred until this audit is reviewed.*
