# Central Wallet — Identity Overlap Safety Gate

**Prompt ID:** `RadiumDesk-P-25-09-83`  
**Date:** 2026-09-27  
**Mode:** PRIVILEGED READ-ONLY / COUNT-ONLY  
**Companion docs:**
- `docs/central-wallet-architecture-discovery-p-RadiumDesk-P-25-09-81.md`
- `docs/central-wallet-production-discovery-p-RadiumDesk-P-25-09-82.md`

**Status:** Safety gate only — no central wallet, no account linking, no data modification.

---

## 1. Objective

Establish **count-only** cross-database email overlap between `radiumbox_prod` and `rdservice_in_prod` to inform central wallet pilot design under the **hybrid hypothesis** (Central Wallet ID now; Group Identity/SSO later — **not approved**).

This analysis does **not** prove that matching emails represent the same person.

---

## 2. Data sources

| Source | Database | Application path | Status |
|--------|----------|------------------|--------|
| radiumbox.com | `radiumbox_prod` | `/var/www/radiumbox.com` | VERIFIED |
| rdservice.in | `rdservice_in_prod` | `/var/www/rdservice.in` | VERIFIED |
| Host | KVM8 `ravi@187.127.129.16` | — | VERIFIED |

### Repository verification (local worktrees)

| Project | Branch | HEAD SHA |
|---------|--------|----------|
| radiumbox.com | `feat/desk-catalog-price-sync` | `116990ab` |
| rdservice.in | `feat/desk-wallet-refund-reversal` | `bcb380ac` |
| radium-desk | `feat/refund-statutory-adjustment-p-25-09-79` | `d6decb21` (before this prompt) |

---

## 3. Access verification

| Access path | Capability | Status |
|-------------|------------|--------|
| Spoke DB users (`radiumbox_prod`, `rdservice_in_prod`) | Single-schema SELECT only | VERIFIED (Phase 2) |
| Cross-schema via spoke users | **Denied** | VERIFIED |
| Privileged read (`sudo mysql` + spoke credentials via read-only PDO) | Cross-schema COUNT queries | VERIFIED this prompt |
| Method | `SELECT` + `SHA2(LOWER(TRIM(email)), 256)` aggregation; **no email values read into output** | VERIFIED |

No credentials, connection strings, or passwords are recorded in this document.

**Read-only verified:** No `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, or temporary persistent objects were used.

---

## 4. Normalization method

### Application behaviour (code review)

| Context | Normalization | Site |
|---------|---------------|------|
| Desk wallet credit account email check | `strtolower(trim($user->email))` | rdservice.in |
| Desk wallet ledger lookup | `strtolower(trim($customerEmail))` | radiumbox.com |
| Checkout / registration user lookup | `User::where('email', $request->email)` — **no case fold** | Both sites |
| Google OAuth lookup | `where('email', $user->email)` — provider casing | Both sites |

**VERIFIED:** There is **no single documented global email normalization** for customer identity across the group. Wallet integration paths use `strtolower(trim(...))`; checkout uses raw email string match (collation-dependent).

### Normalization used for this COUNT-ONLY analysis

```
normalized_email = LOWER(TRIM(email))
```

Empty-after-trim emails excluded: `email IS NOT NULL AND TRIM(email) != ''`

**Rationale:** Aligns with wallet integration services and Phase 2 discovery. Documented here explicitly because checkout does not consistently apply the same rule.

**This does not imply** that two rows sharing a normalized email are the same human.

---

## 5. RadiumBox counts (`radiumbox_prod`)

| Metric | Count | Status |
|--------|------:|--------|
| Total `users` rows | 516,800 | VERIFIED |
| Non-null non-empty email rows | 516,800 | VERIFIED |
| Distinct normalized emails | 516,800 | VERIFIED |
| Duplicate normalized-email groups | 0 | VERIFIED |
| Users in duplicate groups | 0 | VERIFIED |
| `email_verified_at` set | 44,995 | VERIFIED |
| Non-null non-empty phone rows | 431,951 | VERIFIED |
| Distinct phone values (`TRIM(phone)`) | 369,388 | VERIFIED |
| `deleted_at` column | absent | VERIFIED |
| `phone_verified_at` column | absent | VERIFIED |
| Email unique index | present | VERIFIED (Phase 2) |
| Phone unique index | absent | VERIFIED (Phase 2) |

---

## 6. RDService counts (`rdservice_in_prod`)

| Metric | Count | Status |
|--------|------:|--------|
| Total `users` rows | 253,196 | VERIFIED |
| Non-null non-empty email rows | 253,196 | VERIFIED |
| Distinct normalized emails | 253,196 | VERIFIED |
| Duplicate normalized-email groups | 0 | VERIFIED |
| Users in duplicate groups | 0 | VERIFIED |
| `email_verified_at` set | 11,529 | VERIFIED |
| Non-null non-empty phone rows | 252,888 | VERIFIED |
| Distinct phone values (`TRIM(phone)`) | 243,064 | VERIFIED |
| `deleted_at` column | absent | VERIFIED |
| `phone_verified_at` column | absent | VERIFIED |
| Email unique index | present | VERIFIED (Phase 2) |
| Phone unique index | absent | VERIFIED (Phase 2) |

*Note: RDService user total increased by 1 since Phase 2 (253,195 → 253,196); overlap counts reflect this prompt’s query time.*

---

## 7. Cross-site overlap counts

Computed via privileged read-only `SHA2(LOWER(TRIM(email)), 256)` hash groups intersected in memory. **No email values exported.**

| Metric | Count | Status |
|--------|------:|--------|
| Distinct normalized emails in RadiumBox | 516,800 | VERIFIED |
| Distinct normalized emails in RDService | 253,196 | VERIFIED |
| **Shared distinct normalized emails (both DBs)** | **244,462** | VERIFIED |
| RadiumBox users whose normalized email appears in RDService | 244,462 | VERIFIED |
| RDService users whose normalized email appears in RadiumBox | 244,462 | VERIFIED |
| Ambiguous: normalized email → multiple RadiumBox users | 0 groups / 0 users | VERIFIED |
| Ambiguous: normalized email → multiple RDService users | 0 groups / 0 users | VERIFIED |

### Derived rates (INFERRED arithmetic on VERIFIED counts)

| Rate | Value |
|------|------:|
| RadiumBox users with cross-site email match | 47.3% (244,462 / 516,800) |
| RDService users with cross-site email match | 96.5% (244,462 / 253,196) |
| RadiumBox users with **no** RDService email match | 272,338 |
| RDService users with **no** RadiumBox email match | 8,734 |

Because within-site duplicate normalized emails are **zero**, each overlapping normalized email maps to **exactly one** RadiumBox `users.id` and **exactly one** RDService `users.id`. Cross-site ambiguity from multi-user-per-email within a site is **VERIFIED absent**.

---

## 8. Additional identity counts

### Verified-email cross-site overlap

| Metric | Count | Status |
|--------|------:|--------|
| Shared distinct normalized emails where **both** sides have `email_verified_at` | 11,212 | VERIFIED |
| RadiumBox verified users in cross-site verified overlap | 11,212 | VERIFIED |
| RDService verified users in cross-site verified overlap | 11,212 | VERIFIED |

### Phone (count-only; **not used for cross-site matching**)

| Metric | RadiumBox | RDService | Status |
|--------|----------:|----------:|--------|
| Phone present | 431,951 | 252,888 | VERIFIED |
| Distinct phones | 369,388 | 243,064 | VERIFIED |
| Phone duplicate capacity (present − distinct) | 62,563 | 9,824 | VERIFIED |
| Verified phone mechanism | none (`phone_verified_at` absent) | none | VERIFIED |

### Deleted accounts

| Metric | Status |
|--------|--------|
| Soft-delete (`deleted_at`) on `users` | **VERIFIED absent** on both DBs |
| Hard-delete / email reuse behaviour | UNKNOWN |

---

## 9. VERIFIED / INFERRED / UNKNOWN

### VERIFIED

- Privileged cross-database read access established safely via `sudo mysql` / read-only PDO (no schema changes).
- Within each database: **zero** duplicate normalized emails (unique email constraint effective).
- **244,462** shared normalized emails between RadiumBox and RDService.
- Each overlapping email maps 1:1 to one user ID per site (no within-site ambiguity).
- **11,212** cross-site overlaps where email is verified on **both** sides.
- Phone is not unique and has no verified-phone column on either site.
- Checkout lookup does not consistently apply `LOWER(TRIM())` while wallet paths use `strtolower(trim())`.

### INFERRED

- ~96.5% of RDService customers likely already have a RadiumBox account **with the same normalized email string** — this is **string overlap**, not confirmed identity.
- ~47.3% of RadiumBox customers share a normalized email with an RDService account.
- Email-only automatic linking would affect a **large** population; mis-link risk is low **within** the normalized-email model but **same-person confidence is not proven**.
- A Central Wallet ID can be introduced **without** Group SSO if linking uses explicit verification beyond raw email equality.
- Phone must **not** be used as a linking key (no verified-phone mechanism; high collision counts).

### UNKNOWN

- Whether overlapping emails represent the same person (household, shared inbox, typo, transferred account).
- Historical hard-deleted users or email reuse after deletion.
- Whether case/collation mismatches created **false non-overlaps** in live checkout (normalization mismatch between apps).
- Identity of the 8,734 RDService-only emails and 272,338 RadiumBox-only emails.

---

## 10. Security interpretation

### Exact email overlap vs same-person vs confirmed identity

| Concept | This analysis establishes |
|---------|---------------------------|
| **Exact normalized email overlap** | **VERIFIED** — 244,462 distinct values in both DBs |
| **Possible same-person overlap** | **INFERRED** — plausible for many rows, not provable from email alone |
| **Confirmed same-person identity** | **UNKNOWN** — requires verification step |

### Identity confidence tiers (for pilot design)

| Tier | Population (counts) | Confidence |
|------|---------------------|------------|
| A — Normalized email match, both verified | 11,212 users per site | Higher than email-only, still not KYC |
| B — Normalized email match, not both verified | 233,250 users per site (244,462 − 11,212) | Low for automatic linking |
| C — No cross-site email match | 272,338 box / 8,734 rdin | Separate identities until explicit link |

**Critical:** An attacker who knows a victim’s email could not create a duplicate on the same site (unique index), but could already hold an account on the other site with that email. Cross-site wallet linking on email alone would merge **string equality**, not **proof of control across both sites**.

---

## 11. Central-wallet implications

| Question | Assessment | Label |
|----------|------------|-------|
| Duplicate-account risk within one site | Low (unique email) | VERIFIED |
| Cross-site duplicate-account risk | High overlap count; separate `users.id` namespaces | VERIFIED |
| Is email-only linking safe for pilot? | **Not recommended without additional verification** | INFERRED |
| Additional verification required? | **Yes** — at minimum dual-site email verification or explicit customer linking ceremony | INFERRED |
| Central Wallet ID without Group SSO? | **Feasible** if wallet links are explicit, revocable, and not auto-merged on email match alone | INFERRED |
| Auto-merge on email match? | **High risk** — 244k overlaps; no same-person proof | INFERRED |

### Pilot linking principles (architecture requirements, not implementation)

1. **Never** auto-link on normalized email alone.
2. Require **verified email on the initiating site** + **proof of control on the target site** (login, OTP, or manual ops).
3. Treat the 11,212 dual-verified overlap as a **smaller candidate set** for optional accelerated linking — still not automatic without Owner policy.
4. Keep **separate local `users.id`**; central wallet holds links `(central_wallet_id, site, local_user_id)`.
5. Do not use phone for linking.

---

## 12. Owner decisions required

| # | Decision |
|---|----------|
| 1 | Minimum verification for cross-site wallet link (login both sites / email OTP / manual ops only) |
| 2 | Whether dual-verified email overlap (11,212) qualifies for any expedited linking policy |
| 3 | Handling RDService-only accounts (8,734) and RadiumBox-only accounts (272,338) |
| 4 | Whether normalization mismatch (checkout vs wallet) must be fixed before pilot |
| 5 | Approve pilot charter (Option 3 hybrid) with **no auto-merge** rule |

---

## 13. Recommended next gate

1. **Pilot charter sign-off** — hybrid Central Wallet ID, explicit linking only, no email auto-merge.
2. **Linking ceremony design** — document UX and verification for Tier A vs B populations (counts above).
3. **Normalization alignment study** — quantify false **non**-overlaps due to case/whitespace in checkout vs `LOWER(TRIM())` (separate read-only query if needed).
4. **Do not implement** central wallet repository until charter approved.

---

## Appendix — Query method (no PII)

1. Per-database counts via `sudo mysql` `SELECT COUNT(*)`.
2. Cross-site overlap via read-only PDO to each spoke `.env` database user:
   - `SELECT SHA2(LOWER(TRIM(email)), 256) AS h, COUNT(*) AS c ... GROUP BY h`
   - Intersect hash keys in memory; emit aggregate counts only.
3. No emails, names, phones, user IDs, or wallet IDs written to output or files.

---

*End of identity overlap safety gate. No PII exported. No database writes. No application changes.*
