# Release Checklist

Run in order. Do not tag until every item is checked.

- [ ] `composer test` passes locally
- [ ] CI matrix green on the release commit: `gh run watch --exit-status <run-id>` for `tests.yml` — all cells (PHP 8.3–8.5 × Laravel 12/13 × prefer-lowest/prefer-stable × ubuntu/windows)
- [ ] `vendor/bin/pest --filter=Acceptance` — exits 0 (requires node/npx for the emulator)
- [ ] `vendor/bin/pest --filter=ProjectionBoundary` — exits 0
- [ ] `vendor/bin/pest --filter=IdiomCoverage` — exits 0
- [ ] `vendor/bin/pest --filter=WorkbenchZeroSdkReference` — exits 0
- [ ] `grep -rE '(use |\)WorkOS\' workbench/` — exits 1 (zero matches; the raw form of the check above)
- [ ] `grep -cE '^[0-9]+\.' docs/quickstart.md` — prints ≤ 5
- [ ] `ls tests/Feature/*Test.php | wc -l` — ≥ 16
- [ ] `grep -rn 'env(' src/ --include='*.php'` — exits 1 (no matches; also enforced by tests/ArchTest.php)
- [ ] `feature_list.json` reflects true, evidence-backed status for every phase
- [ ] CHANGELOG.md / release notes drafted

The checklist duplicates several already-scripted checks on purpose: a release
can be cut from a commit CI has not finished checking, so this list is the
actual human gate, not a restatement of automation.

## Human Quickstart Trial Log

A recorded human trial reproducing `docs/quickstart.md` end-to-end on a
**fresh** Laravel app (`laravel new`), timed, with orgs + RBAC confirmed live
(the trialist logs in and sees their organization and a claims-backed
`$user->can()` answer — the workbench `/dashboard` route shows the shape to
look for). Required before tagging; the result goes in the release notes
verbatim.

| Trialist | Date | Fresh app? | Elapsed time | Orgs + RBAC live? | Notes |
|---|---|---|---|---|---|
| _(name)_ | _(YYYY-MM-DD)_ | Y/N | _(mm:ss)_ | Y/N | _(anything that snagged)_ |

_If elapsed time exceeds 10 minutes or Orgs + RBAC live = N, the release is
blocked — fix the quickstart or the underlying behavior, then re-trial. Do not
fill in this table without actually running the trial: a fabricated entry
makes the one criterion no CI can check worthless._
