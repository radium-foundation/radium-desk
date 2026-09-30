# Central Wallet Stage 1 — Real Customer Population (Desk)

## Scope

Read-only tooling for **Desk `central_wallets`** created on or after:

**`2026-07-15 00:00:00` Asia/Kolkata**

This is **not** the rdservice.in `users_wallet` population. Prior analyses that cited 329 wallet rows / 247 customers (e.g. `P-28-09-100`) used **rdin local wallet** scope and must not be reused as Desk Central Wallet counts.

## Financial authority

Desk remains the **sole source of truth** for Central Wallet ledger, balance, reservation, commit, and release. RadiumBox may hold only identity/link/cache/read-through state.

## Export command

```bash
php artisan central-wallet:stage1-population-export \
  --cutoff="2026-07-15 00:00:00" \
  --site=radiumbox.com \
  --output=storage/app/stage1-desk-export.json
```

Output includes per-wallet:

- `central_wallet_id`, `desk_customer_id`, balances (ledger / spendable / reserved)
- **Hashed credentials only** (`credential_type`, `provider`, `subject_hash`) — no raw email/mobile/name
- Active `radiumbox.com` account links (if any)
- Ledger review signals (`has_pending_ledger_entries`, negative balances)

## Classification (on RadiumBox)

Run `central-wallet:stage1-population-match` on the export JSON. Categories:

| Category | Meaning | Auto-link |
|----------|---------|-----------|
| `verified_safe_to_link` | Single trusted identity match, no conflicts | Owner-approved apply only |
| `needs_customer_verification` | Ceremony / trusted link required | **No** |
| `ambiguous_fail_closed` | Multiple users or CWID conflicts | **No** |
| `ledger_review_required` | Pending/non-posted/negative ledger | **No** |
| `no_radiumbox_account_found` | No trusted Box identity match | **No** |

**Never** auto-link by email/mobile/name string match alone.

## User 3 validation

Expected path (from design `P-28-09-87`):

- RadiumBox user **3** → existing trusted link → Desk CWID `50ff2e87-6030-4ae8-b93a-163884db90c5`
- Desk authoritative balance read via Stage 1 balance-read API
- rdin user 3 **₹499 local wallet** remains **untouched** (no migration in this prompt)

## Production gate

1. Export from production Desk (read-only)
2. Classify on RadiumBox (dry-run default)
3. Review ambiguity / ledger-review counts
4. **STOP** before `--apply-safe-links` without Owner approval reference
5. Enable balance-read / checkout cohort flags separately after review

## Prompt

- **RadiumDesk-P-30-09-20** — Stage 1 real-customer population export tooling
