# P-17-15 — Mantra technical enquiry (CAPTCHA-free warranty validation)

**Purpose:** Request an **official**, **CAPTCHA-free** channel for device warranty and RD subscription validation so Radium Desk can automate the current manual Servico portal step without OCR, third-party solvers, or undocumented scraping.

**Send from:** Radium Owner / authorized signatory  
**To:** servico@mantratec.com (cc: sales@mantratec.com if commercial partnership required)  
**Subject:** Partner API / authorized validation for RDWarrantyCheck — Radium Softech / Radium Desk integration

---

## Context (for Mantra)

Radium operates **Radium Desk**, an internal service-case system. Authorized administrators validate Mantra device serials against Servico’s **Device Warranty & RD Subscription Check** page:

`https://servico.mantratecapp.com/MasterForms/RDWarrantyCheck.aspx`

Today we use a **Chrome extension** (partner-operated browsers) to:

- Open the page with the serial prefilled
- Correlate results to an internal session via a signed handoff token
- Read the **post-validation** result table (`#TblRDWarrantyCheck`) after the operator completes the **image CAPTCHA** and clicks **Check**

We **do not** auto-fill CAPTCHA, auto-click Check, or scrape pre-validation AJAX. We seek an **OEM-approved** alternative that removes interactive CAPTCHA while keeping auditability.

## Questions for Mantra / Servico

1. **Authorized API:** Is there a documented **server-to-server** or **partner-authenticated** API (REST/SOAP) that returns the same fields as the RDWarrantyCheck result table (serial, warranty status, RD subscription expiry, and related columns) **without** an interactive image CAPTCHA?

2. **Partner exemption:** If no public API exists, can **registered partners** receive:
   - CAPTCHA exemption on `RDWarrantyCheck.aspx`, **or**
   - Access to the **Excel bulk upload** path (≤50 serials) **without per-row CAPTCHA**, **or**
   - A separate **partner portal** workflow for serial validation?

3. **Authentication:** What credentials are required (partner code, API key, OAuth, client certificate, IP allowlist)? What are rate limits and error codes?

4. **Contract & compliance:** What NDA/DPA or distributor agreement is required? Are automated lookups on behalf of end customers permitted under your policy?

5. **Test environment:** Is there a **non-production** endpoint or test serials for integration testing?

6. **Field mapping:** Please provide official documentation mapping API response fields to the columns shown on RDWarrantyCheck (including date formats).

7. **Supported devices:** Confirm model coverage (e.g. MFS110, MIS100, MFS500) and any regional restrictions.

## Integration constraints (Radium)

We will implement **only** against written Mantra documentation and issued credentials. Planned Desk behaviour:

- Reuse existing **session + handoff token** correlation and result validation
- Server-side call from Desk **or** officially supported client SDK — **not** replay of undocumented browser XHR
- Feature flag **off** until production credentials are configured
- Retain current **browser + extension** path as fallback

## Requested deliverables

- PDF or portal link to **official API/integration guide** for warranty + RD subscription lookup
- Sample request/response (redacted)
- Commercial/partner onboarding steps and primary technical contact
- Confirmation whether CAPTCHA-free access is permitted for our use case

---

**Internal reference:** `docs/desk-p17-15-mantra-captcha-free-investigation.md`  
**Prompt:** P-17-15 / `RadiumDesk-P-17-15`