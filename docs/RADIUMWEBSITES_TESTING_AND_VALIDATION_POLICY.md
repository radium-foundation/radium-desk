# **RADIUMWEBSITES — TESTING & VALIDATION POLICY**

**Version:** 1.0  
**Date:** 8 November 2026  
**Applies to:** All independent projects under `/Users/ravi/RadiumWebsites/`

## **1\. Purpose**

Maintain production safety and software quality without allowing slow, repetitive or poorly managed tests to block development.

The guiding principles are:

* Fast feedback during development.  
* Risk-scaled testing.  
* Full regression coverage at appropriate release gates.  
* No unbounded or duplicate test runs.  
* No false claims that skipped or incomplete tests passed.  
* Production safety remains mandatory.

## **2\. Roles and authority**

**Owner**

* Approves production deployment, production data changes, financial actions and external operations.  
* Makes business, statutory and architectural decisions.

**ChatGPT**

* Plans validation and writes Cursor prompts.  
* Reviews evidence and identifies missing gates.  
* Recommends whether work is ready to merge or release.  
* Does not execute repository, Git or production operations.

**Cursor**

* Executes repository changes, tests, CI-related work, Git operations and technical verification.  
* Diagnoses ordinary test and build failures.  
* Reports exact evidence and remaining blockers.

## **3\. Risk classification**

Every task must be classified before implementation.

| Risk | Examples | Validation |
| ----- | ----- | ----- |
| Low | Isolated UI, styling, documentation | Changed-file checks and relevant focused tests |
| Normal | Business logic, APIs, application workflows | Focused tests, affected regression suites, lint and build |
| High | Payments, wallets, inventory, invoices, customer data, database, integrations, infrastructure | Focused tests, full affected customer journeys, database-specific tests and appropriate concurrency validation |

High-risk work must never bypass its affected customer-journey regression requirements merely because a focused test passes.

## **4\. Four-level testing strategy**

### **Level 1 — Development feedback**

Run tests relevant to the changed code.

Examples:

* Specific PHPUnit test classes.  
* Relevant feature tests.  
* Changed-file formatting checks.  
* Targeted frontend tests.

Objective: identify defects quickly.

Do not run the entire repository test suite after every small change unless a specific dependency or regression risk justifies it.

### **Level 2 — Pre-commit validation**

Before committing:

* Run all affected focused tests.  
* Run relevant regression suites.  
* Run Pint or the applicable linter.  
* Run the production build or strictly non-mutating build validation.  
* For high-risk changes, run affected customer-journey tests and required database tests.

All required checks must pass before commit. Unavailable checks must be reported as BLOCKED or NOT RUN.

### **Level 3 — Pull request / CI validation**

Use automated CI for broader regression coverage.

Where practical:

* Split independent test suites into parallel jobs.  
* Use isolated databases for each job.  
* Cache dependencies safely.  
* Preserve test reports and failure artifacts.  
* Run the full repository suite once at the appropriate pre-merge gate.

A full-suite failure must be diagnosed, not blindly repeated.

### **Level 4 — Release validation**

Before release:

* Verify the exact source commit.  
* Verify required CI results apply to that commit.  
* Verify migration compatibility.  
* Verify backup integrity and restorability when production deployment requires it.  
* Complete the affected customer journeys.  
* Respect Owner approval gates.

A merge is not a deployment.

## **5\. Full PHPUnit execution policy**

A full repository PHPUnit run is a broad regression check, not a routine development loop.

Mandatory rules:

1. At most **one authoritative full-suite run per worktree** at a time.  
2. Do not start another full run while the authoritative run is active.  
3. Use a persistent log and capture the final exit code.  
4. Capture JUnit or equivalent machine-readable results when supported.  
5. Record the last completed test and current test when diagnosing a slow run.  
6. Use a reasonable timeout based on measured historical runtime and test configuration.  
7. Never use an unlimited execution setting as a substitute for process monitoring.  
8. Do not restart a failed run without investigating why it failed.  
9. Preserve the original failure logs before retries.  
10. Do not hold unrelated development work hostage to a full-suite run.

### **When a full run is slow**

Cursor must inspect:

* Whether the process is alive.  
* CPU and memory activity.  
* Current and last completed test.  
* Database connections, locks and query activity.  
* Log timestamps and output progress.  
* Child processes and duplicate PHPUnit processes.

A high CPU reading alone does not prove progress.

### **Stall policy**

If there is no measurable progress:

1. Record the process and test state.  
2. Identify the likely blocking operation.  
3. Preserve logs and database evidence.  
4. Stop the process gracefully if a genuine stall is established.  
5. Report the full-suite gate as BLOCKED if it did not complete.

Do not leave an unexplained test process running indefinitely.

### **Runtime expectations**

There is no universal PHPUnit runtime limit. Establish a baseline from actual project history.

If a run materially exceeds its normal runtime, investigate it rather than automatically allowing another long run.

A run taking hours must have evidence of progress and a diagnosed reason. It must not be treated as normal merely because it is still consuming CPU.

## **6\. MariaDB and database testing**

Database-specific behavior must be tested against the database engine used by the application when the change depends on engine-specific behavior.

For MariaDB concurrency tests:

* Use a dedicated disposable database.  
* Bind test services to loopback.  
* Use a non-production port.  
* Verify database identity before running tests.  
* Never use production credentials.  
* Never point test cleanup or migrations at a production database.  
* Ensure independent workers use independent connections.  
* Capture test output, exit codes and final database invariants.

For inventory and serial-exchange changes, validate:

* Same original serial submitted concurrently.  
* Same replacement serial submitted concurrently.  
* Same idempotency key submitted concurrently.  
* Different serial exchanges against the same sale.  
* No partial writes after rejected or failed operations.  
* Correct sale assignment, serial status, inventory movements and audit records.

Race-sensitive scenarios may be repeated to detect intermittent failures. Repetition must be bounded and justified.

**Two successful runs are a useful additional confidence check, not a universal requirement for every database test.** CI should provide repeatable coverage where infrastructure permits.

If a disposable database cannot be provisioned safely, report the gate as BLOCKED. Skipped tests are not passes.

## **7\. Baseline failures and legacy test suites**

Existing failures must be distinguished from regressions.

When a suite has known baseline failures:

* Record the exact baseline commit.  
* Reproduce failures on that baseline using an isolated worktree or equivalent method.  
* Compare the same tests and relevant environment.  
* Record exact failure names and counts.  
* Do not repeatedly investigate unchanged baseline failures.  
* Investigate every new or materially changed failure.

A failure is not pre-existing merely because an earlier report called it a baseline issue.

Do not weaken assertions, remove tests or hide failures to obtain a green result.

## **8\. Retry policy**

A retry is justified when:

* A transient infrastructure failure is identified.  
* A test environment stopped unexpectedly.  
* A specific flaky or race-sensitive test needs confirmation.  
* A correction has been made and affected tests need rerunning.

A retry is not justified merely because a previous run took a long time.

Before retrying:

1. Preserve the previous result.  
2. Identify the failure reason.  
3. Confirm no duplicate process remains.  
4. Confirm the environment is stable.  
5. Run the smallest relevant validation first.

Do not repeatedly rerun the entire repository suite to diagnose one failing test.

## **9\. Parallel development and process management**

* Do not run duplicate full PHPUnit suites in the same worktree.  
* Do not run competing migrations against the same test database.  
* Do not allow cleanup processes to terminate another active test run.  
* Use isolated worktrees and databases for genuinely independent work.  
* Do not modify a worktree while its validation process depends on the files being tested.  
* Parallelize independent CI jobs rather than creating uncontrolled local background processes.

## **10\. Merge readiness**

Before merge, verify:

* Exact repository, branch, base and HEAD.  
* Complete PR diff.  
* Relevant tests and quality checks.  
* Required CI results for the exact commit.  
* Database migration safety.  
* Branch-protection requirements.  
* No unresolved high-risk regression.

A full-suite failure does not automatically prove a code defect. However, an incomplete required check must remain explicitly visible.

Any exception to a required validation gate must be documented with:

* The missing check.  
* Why it could not be completed.  
* What alternative evidence exists.  
* Residual risk.  
* Who is authorized to accept that risk.

Do not silently waive a high-risk validation requirement.

## **11\. Production and deployment separation**

Testing authorization never authorizes production changes.

Production deployment, production data changes, payment actions and external operations remain subject to explicit Owner approval.

Before production deployment:

* Verify exact target environment.  
* Verify backup integrity and restorability.  
* Verify migration and rollback compatibility.  
* Complete deployment preflight.  
* Preserve the required recovery checkpoint.  
* Stop if any mandatory safety check fails.

A successful CI run, merge or release tag does not establish that production is safe or that a release is known-good.

## **12\. Mandatory reporting format**

Every validation report must include:

* Global prompt ID and repository Cursor ledger ID.  
* Exact repository, branch, base and HEAD.  
* Worktree state.  
* Tests run and exact commands.  
* PASSED / FAILED / BLOCKED / NOT RUN status.  
* Test, assertion, failure, error and skip counts.  
* Exit codes.  
* Runtime and timestamps with timezone.  
* Database environment and isolation evidence where relevant.  
* Logs and report locations.  
* Preserved / Extended / Changed / Removed.  
* Commit, push and merge status.  
* Deployment status.  
* Remaining risks and blockers.

Never report a skipped, aborted, incomplete or unavailable test as passed.

Use actual measured timestamps and durations. If unavailable, report UNKNOWN rather than estimating.

## **13\. Development efficiency rule**

The objective is to maximize confidence per unit of developer time.

Cursor must:

* Prefer focused tests during development.  
* Use full suites at meaningful gates.  
* Reuse verified baseline evidence.  
* Diagnose before retrying.  
* Avoid duplicate processes.  
* Use CI and parallel execution where safe.  
* Keep unrelated development moving.  
* Escalate only genuine Owner decisions, architectural issues or critical safety risks.

**Final principle: Protect production without turning validation into a development blockade.**

