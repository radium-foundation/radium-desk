# P-17-32 — POS bulk serial Owner UAT (post-deploy, isolated data)

**Gate:** Required before treating **v4.1.84** as known-good for POS counter bulk serials.

## Preconditions

- Deployed build matches **`v4.1.84`** / `release.json` build from merge **#323**.
- Test user with **`pos.sell`** on an authorized branch.
- **Isolated test serials only** — no real customer orders, no production customer phone/GSTIN, no live payment capture unless a dedicated sandbox flow exists.

## Checklist

1. **Bulk paste (~30 serials):** Select a serialized SKU → paste newline-separated valid available serials → **Validate and add** → feedback shows parsed count and **N serials added**; cart qty matches **N**; selected chips match.
2. **Mixed batch:** Include one invalid / wrong-branch / already-sold serial → errors list rejected SNs; valid SNs still added; no silent drop.
3. **Duplicate in paste:** Repeat one SN in the list → no duplicate cart chips; server allocation rules unchanged on complete.
4. **Single-serial scan-first:** Type or scan one SN → **Validate and add** (or Ctrl/Cmd+Enter) → one unit added.
5. **No duplicate allocation:** Complete sale with test customer only if authorized; second sale must not accept the same serial (existing server guard).

## BLOCKED if

- No branch with disposable serialized stock for test.
- Only production serials available (do **not** use).

Mark **BLOCKED** in deploy report rather than improvising production data.