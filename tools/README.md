# IMDb Guard — local batch tools

The `local/` directory is the version-controlled home of the poster batch
import workflow that used to live only on the editor's machine. Everything
runs from a checkout of this repo — no hardcoded paths, no hardcoded keys.

## The workflow

1. **Export a manifest from WordPress.** Reviews → IMDb Guard → *Local batch
   import (poster manifests)*. Pick a source (Reviews, or the full Oscars
   film catalogue) and a scope (missing posters only, or everything). Every
   line carries the film's **IMDb ID and its title** together:

   ```
   tt0038192 | The True Glory (1945)
   ```

2. **Save it as `titles.txt`** inside `tools/local/` (git-ignored).

3. **Provide the TMDB key** — one of (checked in this order):
   - `TMDB_API_KEY` environment variable
   - first line of a git-ignored `tools/local/tmdb-key.txt`
   - typed at the launcher prompt

   The key is deliberately never stored in this repository.

4. **Run a launcher** (`IMDb_Poster_Preflight__Local.bat` or
   `TMDB_Poster_Download__Local.bat` — same engine, both kept so existing
   shortcuts work) and choose:
   - **1) Preview** — writes `poster-preflight-report-*.csv` (per line:
     IMDb id, TMDB match, title-match verdict, poster availability) without
     downloading anything.
   - **2) Download** — saves posters into `poster-output/`, each named
     `tt0038192 - The True Glory (1945).jpg`. The id in the filename is what
     the WordPress media importers key on; the title keeps the media library
     human-readable.

5. **Bulk-upload `poster-output/` to the WordPress media library**, then run
   the poster importer in the Oscars Ledger admin — it maps each file by the
   tt-id in its filename, and the entity plates fill across the site.

## Notes

- Title-only lines are resolved through TMDB search and their IMDb id is
  fetched from TMDB's external ids, so output filenames still carry the
  bridge id whenever TMDB knows it.
- Title mismatches (the manifest title vs. what TMDB returned for that id)
  are flagged `title match: NO` in the console and report — the whole reason
  the manifest carries both fields. Preview first on big batches.
- The engine is `local-poster-preflight.ps1` (Windows PowerShell 5.1+).
  Parameters: `-InputFile`, `-Mode preview|download`, `-Source tmdb`,
  `-TmdbApiKey`, `-OutputDir`, `-PosterSize w342|w500|w780|original`,
  `-SleepMs`, `-NoPrompt`.
