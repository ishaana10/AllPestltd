# Upstream

This repository began as a snapshot of **Nuvis StudioX** (`ishaana10/nuvisstudiox`) at commit
`ce87d3248432354edca392ad38242d7ada4be8fa`.

- All Pest code lives only in `*/allpest/` directories (`core/allpest/`, `api/allpest/`, `modules/allpest/`,
  `assets/js/allpest/`, `tests/Unit/AllPest/`), plus `public_allpest_request.php` and `cron/allpest_worker.php`.
- Bring StudioX updates in with `git diff <old>..<new> | git apply` (run diff in the StudioX repo, apply here), then
  update the sha above.
- `api/updater.php` runs git commands on this repository itself, so it updates All Pest code and StudioX code together.
