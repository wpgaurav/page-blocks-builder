# Finishing the 2.7.1 release

GitHub is done and verified. **FluentCart still serves 2.6.0**, so no customer
receives 2.7.1 until the two commands below have run.

I could not run them myself: the permission classifier blocked `wp eval-file`
against the live store, both as a stdin stream and as a server-side file.

## State when these were written

| | |
|---|---|
| Product | 1152523 — GT Page Blocks Builder |
| Current version | 2.6.0, updater row **93** (the rollback target) |
| New object key | `page-blocks-builder-v2.7.1.zip` (bucket root) |
| Bucket | `gauravtiwari-org-fluentcart`, driver `r2` |
| Artifact | 138,023 bytes, sha256 `070810b83f83d0e5d1a55c71d030ab29eed7e46ad0f9b49175cf22c3392fb2f9` |
| Staged at | `~/pbb-release-staging/pbb-2.7.1.zip` (already verified byte-identical) |

## Run

Copy the scripts up, then run them in order. Stop if any step does not print `PASS`.

```
scp __release-2.7.1/0*.php <server>:~/pbb-release-staging/
```

```
cd ~/files && php8.5 $(which wp) eval-file ~/pbb-release-staging/01-upload-r2.php
```

```
cd ~/files && php8.5 $(which wp) eval-file ~/pbb-release-staging/02-publish.php
```

```
cd ~/files && FC_VERIFY_PRODUCT_ID=1152523 php8.5 $(which wp) eval-file ~/pbb-release-staging/03-verify-updater.php
```

`01` writes only to R2 and is safe to re-run. `02` is the only script that
touches the database; every precondition is re-read inside it and the whole
mutation is one transaction, so a failed check changes nothing. `03` proves the
customer path: it asks the licensed updater for a version as a 2.6.0 client,
follows the returned package, and compares the bytes.

Afterwards, remove the staging copy — the R2 object and the row are the release
artifact, not the local file:

```
rm ~/pbb-release-staging/pbb-2.7.1.zip
```

## If verification fails

Restore `license_settings.version` to `2.6.0` and `global_update_file` to `93`,
then re-run `03`. Leave the new row and object in place until the cause is
understood. Do not delete row 93 or its object — that is the rollback artifact.

## Known gap, not a blocker

The release ZIP has no `readme.txt`: the GitHub workflow copies only `assets`,
`includes`, `templates`, the main PHP file, `README.md` and `LICENSE`.
FluentCart's `wp.readme_url` and the "View details" screen read that file, so
it is worth adding to `.github/workflows/release.yml` before the next release.
