# Production SignedInvoice backfill — P-07-09-202

**Project:** Radium Desk  
**Prompt ID:** RadiumDesk-P-07-09-202  
**Date:** 2026-09-10  
**HEAD:** `85b9cc5a2daec2c2823b9a036ffc74e6e3e2f90f`  
**Production:** KVM8 `srv1910783` `/var/www/radium-desk`

One `EInvoiceIrnRecoveryService::recover()` for INV-076746 / 1062 / RDE318400. IP from `STATUTORY_EINVOICE_GSP_IP_ADDRESS`. No in-process override. No GENERATE. No deploy.

| Item | Result |
|------|--------|
| Auth | 1 `GET /einvoice/authenticate` |
| Get-IRN | 1 `GET /einvoice/type/GETIRNBYDOCDETAILS/version/V1_03` (`param1=INV`, `docnum=INV-076746`, `docdate=10/09/2026`, IP match, password header empty) |
| GENERATE / Cancel / retry | 0 |
| HTTP / status_cd | success / `1` |
| Returned IRN | match stored `974b217a7c86b097e083d625fa28691e11879539376c1a2bf5d670c309438119` |
| SignedInvoice | PRESENT, 2109 bytes (contents not printed) |
| Path | `statutory-einvoice/1062/signed-invoice.txt` on `local` |
| SHA-256 | `c6ec9a86a25d5b6d729eade03230f6a7a28b6785d7c3f2f1640dee124ccfcae2` |
| Perms | dir `700`, file `600` |
| Public URL | none |
| IRN/Ack/AckDt/QR | unchanged |
| Invoice | `issued` unchanged |
| e-invoice | `submitted`, 1 row |
| `e_invoice_records.updated_at` | updated by metadata persist only (`2026-09-10 17:03:14`) |

Provider `none`. Null gateway. Worker false. Auto-issue false.
