# Lunara IMDb Guard

Private WordPress plugin source for Lunara Film IMDb validation and editorial audit tooling.

## Role

This plugin validates Review IMDb IDs against title/year data, helps fill clear matches, and provides an editorial audit surface in WordPress.

## Source Locations

- Local source: `G:\lunara-backups\work\lunara-imdb-guard`
- Live plugin: `/home/151589083/htdocs/wp-content/plugins/lunara-imdb-guard`
- Continuity workspace: `C:\Users\silve_i21do49\OneDrive\Desktop\New folder`

## Version

Current baseline: `0.4.1`.

## Secrets

The Git baseline intentionally does not include a default OMDb API key. Configure runtime access through the WordPress setting `lunara_imdb_guard_omdb_api_key` or a server-side `LUNARA_IMDB_GUARD_OMDB_API_KEY` constant.

## Verification

- Run PHP lint on `lunara-imdb-guard.php` after edits.
- Run `php tests/review-header-context-regression.php` after edits.
- Confirm the admin audit/settings screen loads.
- Confirm Review edit screens still show IMDb Guard status/actions.
