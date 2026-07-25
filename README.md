# Camera Files Rename

A single-file PHP CLI tool that batch-renames camera files to `YYYYMMDD_HHIISS[_IDENTITY]_FILESIZE_MAKEMODEL.ext` (for example, `20241102_153045_S798_79979520_LEICAQ3.dng`). It extracts EXIF/mtime datetime, an optional metadata identity, usable EXIF camera make/model, and the raw byte size with a lowercased extension; `.jpg` output is normalized to `.jpeg`. Camera fields are uppercased, stripped to alphanumeric characters, and concatenated. Missing or unrecognizable make/model fields are omitted. Supports duplicate handling with `(001)`, `(002)` suffixes, concurrent execution safety via file locking, and debug mode.

---

## Requirements

- **PHP** 8.1+ with the `exif` extension
- **ImageMagick** for `.heic` files — the script searches `PATH` and common macOS locations (`/opt/homebrew/bin/magick`, `/usr/local/bin/magick`, etc.), or the path in `CAMERAFILESRENAME_MAGICK`; it converts to a temp `_camerafilesrename_heictojpeg_*.jpeg` in the target dir to read EXIF, then deletes it
- **macOS** (for the Automator integration below; the script itself also runs on Windows and Linux)

---

## Usage

Accepts any combination of directories and files, supports `--debug`, and stdin fallback.

```bash
php camerafilesrename.php <directory|file> [<directory|file> ...]   # any combination
echo <path> | php camerafilesrename.php                             # read paths from stdin
php -l camerafilesrename.php                                        # syntax check
php tests/run_tests.php                                             # framework-free test suite; always run after editing
```

### Options

- `--debug` — Writes all terminal output plus the original arguments and resolved paths to `_camerafilesrename_debug_YYYYMMDD_HHIISS.txt` in the first target directory.
- `--progress` — Emits optional machine-readable per-file progress events for custom integrations.
  The simple Automator shell wrapper does not use this option.

---

## Automator Quick Action for Finder (macOS)

Follow these steps to add a right-click menu item in Finder that renames selected camera files.

### 1. Open Automator

- Press `Cmd + Space`, type **Automator**, and open it.
- Choose **New Document** → **Quick Action**.

### 2. Configure the workflow settings

In the top-right panel of the workflow canvas, set:

| Setting          | Value                          |
|------------------|--------------------------------|
| **Workflow receives current** | `files or folders`             |
| **in**           | `Finder`                       |
| **Input is**     | `Entire selection`             |

### 3. Add a "Run Shell Script" action

1. Search for **Run Shell Script** in the left sidebar.
2. Drag it into the workflow canvas.
3. Set **Shell** to `/bin/zsh` (or leave the default shell).
4. Set **Pass input** to **as arguments**.
5. Open [`camerafilesrename-automator.sh`](camerafilesrename-automator.sh) in a text editor.
6. Change `PHP_SCRIPT` at the top if the path differs on your Mac.
7. Copy the complete file into the **Run Shell Script** action.

The wrapper launches PHP once with the complete selection and preserves the PHP script's
directory grouping and collision handling. The command output is shown in Automator's result
area. This simple action does not provide per-file progress in Automator's menu-bar indicator.

### 4. Save the Quick Action

- Press `Cmd + S`.
- Name it **Camera Files Rename** (or whatever you prefer).
- The Quick Action is saved to `~/Library/Services/` and appears immediately in Finder.

### 5. Use it in Finder

1. Select one or more camera files or folders in Finder.
2. Right-click and choose **Quick Actions** → **Camera Files Rename**.
3. A native macOS dialog will pop up showing the rename results.

---

## How it works

- **Output names:** Files use `YYYYMMDD_HHIISS_FILESIZE_MAKEMODEL.ext`. EXIF Make and Model are
  uppercased, reduced to alphanumeric characters, and concatenated. Missing or unrecognizable
  fields are omitted. For Leica metadata, normalized Make `LEICACAMERAAG` is omitted when the
  normalized Model contains `LEICA` (for example, `Leica Camera AG` + `Leica Q3` becomes
  `LEICAQ3`). `.jpg` inputs are written with a `.jpeg` extension; `.jpg` remains accepted when
  recognizing legacy renamed filenames. When available, an identity token is inserted after
  the timestamp: `S` + sub-second time, `F` + Leica source frame number, `U` + EXIF
  `ImageUniqueID`, or `X` + an XMP document/instance identifier.
- **Date and identity extraction:** EXIF `DateTimeOriginal` → `DateTime` → `FileDateTime`; `.mov`/`.mp4` always use file mtime. Identity priority is `SubSecTimeOriginal`/`SubSecTime` → Leica source frame number → `ImageUniqueID` → XMP document ID. For `.raf` files, EXIF is read from the embedded JPEG preview, since PHP cannot parse the RAF container directly.
- **Duplicate handling:** If two files still have the same datetime, identity (or no identity), byte size, normalized camera suffix, and output extension, the first gets the plain name, duplicates get `(001)`, `(002)`… suffixes. No content hash is calculated.
- **Concurrency safety:** Per-directory `flock()` locking prevents race conditions when multiple Finder selections launch parallel instances.
- **Idempotency:** A file is skipped only when its encoded byte size matches the current file and its camera/identity suffix and extension are current. Changed-size files are reprocessed; formatted names without a camera or identity suffix are inspected for metadata, and legacy `.jpg` names are migrated to `.jpeg`.
- **iCloud placeholder guard (macOS only):** Files stored in iCloud Drive with "Optimize Mac Storage" enabled may exist as lightweight placeholders locally, where only a Quick Look preview is kept and the full EXIF data is unavailable. The script detects this in two ways: (1) `mdls kMDItemIsDownloaded` where supported, and (2) a disk-usage heuristic — if a photo file (> 100 KB) has no EXIF date and less than 8 KB is actually allocated on disk, it is skipped with a message instead of being renamed to a zero-date name.

---

## Safety notes

- The script renames files **in place** and is **non-recursive**.
- Always test on a throwaway directory with copies before using it on real photo archives.
- If fixtures are missing for tests, regenerate them with: `php tests/generate_fixtures.php` (requires `magick`).

---

## License

This is a personal utility script. Use at your own risk.
