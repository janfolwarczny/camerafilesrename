# AGENTS.md

## What this is

Single-file PHP CLI tool (`camerafilesrename.php`) that batch-renames camera files to
`YYYYMMDD_HHIISS_FILESIZE.ext` (e.g. `20241102_153045_25165824.raf`): EXIF/mtime datetime, raw
byte size, lowercased extension. No composer, no CI, no README.

## Run & verify

```bash
php camerafilesrename.php <directory|file> [<directory|file> ...]   # any combination
echo <path> | php camerafilesrename.php                             # read paths from stdin
php -l camerafilesrename.php                                        # syntax check
php tests/run_tests.php                                       # framework-free test suite; always run after editing
```

- `tests/run_tests.php` copies the committed binaries from `tests/fixtures/` into temp dirs and
  asserts directory/file-mixed-mode behavior. If fixtures are missing or need regenerating:
  `php tests/generate_fixtures.php` (requires `magick`; the suite itself does not).

- Requires PHP 8.1+ with the `exif` extension.
- Requires ImageMagick (`magick` on PATH) for `.heic` files: it converts to a temp
  `_camerafilesrename_heictojpeg_*.jpeg` in the target dir to read EXIF, then deletes it.
- The script renames files in place and is non-recursive. Never "test" it against a real photo
  directory; use a throwaway dir with copies.

## Behavior to preserve when editing

- Date source order: EXIF `DateTimeOriginal` → `DateTime` → `FileDateTime`; `.mov`/`.mp4` always
  use file mtime. mtime-based dates are formatted in PHP's default timezone (`date.timezone`),
  EXIF dates as-is. If no date is found the prefix is all zeros (`00000000_000000`).
- A leading all-zeros prefix means "no date found on a previous run" — it is stripped so the file
  gets re-processed. Re-processing a file that still has no date logs a "new filename already
  exists" error against itself and moves on; that is expected noise, not a real conflict.
- Files renamed with the previous scheme (`YmdHis_<original name>`, e.g. `20241102153045_DSCF1234.RAF`)
  pass the original-name gate and are re-renamed to the new scheme on the next run. Files already
  in the new scheme (`\d{8}_\d{6}_\d+`) are skipped as "Already renamed".
- CLI argument semantics: any combination of directories and files is accepted. Directories are
  scanned non-recursively; files are processed individually. If no arguments are given, the script
  reads paths from stdin (one per line). Missing paths and completely empty input (no args and no
  stdin) exit 1 with a message on STDERR. When arguments are present, stdin is ignored.
- Arguments are grouped by parent directory. Collision detection and `_camerafilesrename_*.log` files are
  per-directory, so two files in different directories can produce the same target name without
  conflict. Duplicate arguments (same realpath) are deduplicated and processed once.
- Filename handling is case-insensitive: all names are lowercased into a per-directory index first,
  and the whole run exits if two *different* files in the same directory differ only by case.
- The original filename is not part of the new name, so two different files with the same
  datetime and byte size collide: the first wins, the second is appended to
  `_camerafilesrename_<unixtime>.log` in the target dir and left untouched. New names are registered
  in the index after each rename so within-run collisions are caught (rename() would otherwise
  silently overwrite). In file-list mode the index only contains the listed files, so the same
  target name is also checked against existing files on disk to avoid overwriting them. Log files
  are skipped on subsequent runs (`_camerafilesrename_*.log`).
