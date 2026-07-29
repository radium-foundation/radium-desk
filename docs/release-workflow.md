# Release Workflow

Mandatory process for every production release. Cursor agents follow `.cursor/rules/release-workflow.mdc`.

## Release Guard

Before suggesting `git tag`, `git push`, `deskd`, or any production release, **always**:

1. Determine the next release version.
2. Verify `CHANGELOG.md` already contains an entry for that **exact** version.
3. If it does **not**:
   - **Stop immediately.**
   - Draft user-facing release notes.
   - Show them for approval.
   - **Do not suggest any Git commands** until the changelog is approved.

This guard prevents releases from accidentally skipping `CHANGELOG.md`.

## Validation

Before any release, verify:

| Check | Requirement |
|-------|-------------|
| CHANGELOG version | == Git tag (`4.0.4` ↔ `v4.0.4`) |
| Git tag | == release version |
| What's New | Reads from the same version |
| `release.json` | Deployment-generated only — never edit manually |

If any mismatch exists, **stop and explain the mismatch**.

## Final Release Checklist

Before every release, confirm:

- [ ] `CHANGELOG.md` updated
- [ ] Version reviewed
- [ ] Commit created
- [ ] Tag created
- [ ] Push main
- [ ] Push tag
- [ ] Run `deskd`
- [ ] Verify `release.json`
- [ ] Verify What's New
- [ ] Verify footer version/build

## After changelog approval

```bash
git add CHANGELOG.md
git commit
git tag vX.Y.Z
git push origin main
git push origin vX.Y.Z
deskd
```

## Versioning

Semantic versioning: `v4.0.3`, `v4.0.4`, `v4.0.5`, …

No four-part versions unless explicitly requested.

## Release notes style

**Good**

- Commercial State added to Customer 360
- Improved Business Timeline accuracy
- Fixed case closure attribution

**Avoid**

- Internal class or method names
- Refactoring details
- Raw commit messages

## Changelog format

```markdown
## 4.0.4 — 2026-07-30 — Short Title

- Bullet one
- Bullet two
```

## How it works in code

| Piece | Location |
|-------|----------|
| Changelog | `CHANGELOG.md` |
| Version detection | Latest semver Git tag (`GitReleaseInspector`) |
| Deploy snapshot | `php artisan release:snapshot` → `storage/app/private/release.json` |
| UI | `ChangelogService` + `VersionService` |

Deploy runs `release:snapshot` automatically via `./tools/desk deploy` (`deskd`).
