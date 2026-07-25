# AGENTS.md

## What this is

Single-file PHP CLI tool (`camerafilesrename.php`) that batch-renames camera files to
`YYYYMMDD_HHIISS_FILESIZE_MAKEMODEL.ext` (e.g. `20241102_153045_25165824_FUJIFILMXT5.raf`):
EXIF/mtime datetime, raw byte size, and an optional normalized EXIF MakeModel suffix. Camera
fields are uppercased, stripped to alphanumeric characters, and concatenated. `.jpg` output is
normalized to `.jpeg`; the renamed-file pattern still accepts `.jpg` for legacy names. No
composer or CI.

## Run & verify

```bash
php camerafilesrename.php <directory|file> [<directory|file> ...]   # any combination
echo <path> | php camerafilesrename.php                             # read paths from stdin
php -l camerafilesrename.php                                        # syntax check
php tests/run_tests.php                                             # framework-free test suite; always run after editing
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
  PHP's `exif_read_data()` cannot parse the `.raf` container, so for `.raf` the EXIF is read from
  the embedded JPEG preview (offset/length are big-endian uint32 at header `0x54`/`0x58`), which is
  extracted to a temp `_camerafilesrename_raftojpeg_*.jpeg` in the target dir and deleted after
  reading. Non-Fuji or truncated `.raf` files fail the extraction and fall through to the normal
  fallbacks (typically the zero prefix), exactly as before.
- A leading all-zeros prefix means "no date found on a previous run" — it is stripped so the file
  gets re-processed. Re-processing a file that still has no date logs a "new filename already
  exists" error against itself and moves on; that is expected noise, not a real conflict.
- Generated names are `YYYYMMDD_HHIISS_FILESIZE_MAKEMODEL.ext`; the camera part is omitted when
  neither EXIF field is usable. A normalized Make of `LEICACAMERAAG` is omitted when the
  normalized Model contains `LEICA`, so `Leica Camera AG` + `Leica Q3` becomes `LEICAQ3`.
- `.jpg` inputs are renamed with a `.jpeg` extension. The unified renamed-file pattern accepts
  both `.jpg` and `.jpeg` so legacy `.jpg` names can be migrated to `.jpeg`.
- The single `RENAMED_FILENAME_PATTERN` recognizes the date, byte-size, optional alphanumeric
  camera suffix, optional `(001)` collision suffix, and supported extension. A file is skipped as
  "Already renamed" only when its encoded size matches the current byte size and its camera/
  extension form is current. A changed byte size is reprocessed, including for files that already
  have a MakeModel suffix. A date/size name without a camera suffix is inspected for EXIF so a
  missing suffix can be added.
- Files renamed with the previous scheme (`YmdHis_<original name>`, e.g. `20241102153045_DSCF1234.RAF`)
  pass the original-name gate and are re-renamed to the new scheme on the next run.
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
  datetime, byte size, normalized camera suffix, and output extension collide: the first wins, the second is appended to
  `_camerafilesrename_<unixtime>.log` in the target dir and left untouched. New names are registered
  in the index after each rename so within-run collisions are caught (rename() would otherwise
  silently overwrite). In file-list mode the index only contains the listed files, so the same
  target name is also checked against existing files on disk to avoid overwriting them. Log files
  are skipped on subsequent runs (`_camerafilesrename_*.log`).
