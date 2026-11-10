# P0 production CPU remeasure artifacts (2026-08-07)

Local-only sampler outputs for [p0-production-remeasure-after-optimizations.md](../p0-production-remeasure-after-optimizations.md).

These files must **not** ship in KVM application rsync (`tmp/` is excluded from deploy filters). Historical copies were removed from the git release tree in P-11-01; regenerate under `tmp/p0-remeasure-*/` on the operator workstation when re-running the sampler (`run-sampler.sh` from a local tools checkout).
