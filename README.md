# Camera Files Rename

A single-file PHP CLI tool that batch-renames camera files to `YYYYMMDD_HHIISS_FILESIZE.ext` (e.g. `20241102_153045_25165824.raf`). It extracts EXIF/mtime datetime and appends the raw byte size with a lowercased extension. Supports duplicate handling with `(001)`, `(002)` suffixes, concurrent execution safety via file locking, and debug mode.

---

## Requirements

- **PHP** 8.1+ with the `exif` extension
- **ImageMagick** (`magick` on PATH) for `.heic` files — it converts to a temp `_camerafilesrename_heictojpeg_*.jpeg` in the target dir to read EXIF, then deletes it
- **macOS** (for the Automator Quick Action below; the script itself also runs on Windows and Linux)

---

## Usage

Accepts any combination of directories and files, supports `--debug` and stdin fallback.

```bash
php camerafilesrename.php <directory|file> [<directory|file> ...]   # any combination
echo <path> | php camerafilesrename.php                             # read paths from stdin
php -l camerafilesrename.php                                        # syntax check
php tests/run_tests.php                                             # framework-free test suite; always run after editing
```

### Options

- `--debug` — Writes all terminal output plus the original arguments and resolved paths to `_camerafilesrename_debug_YYYYMMDD_HHIISS.txt` in the first target directory.

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
3. In the action settings, set:
   - **Shell:** `/bin/zsh`
   - **Pass input:** `as arguments`

4. Paste the following script into the code box:

```bash
RESULT=$(/opt/homebrew/bin/php /Users/janfolwarczny/Work/camerafilesrename/camerafilesrename.php "$@" 2>&1)
osascript -e "display dialog \"$RESULT\" with title \"Camera Files Rename\" buttons {\"OK\"} default button \"OK\""
```

> **Note:** Adjust the PHP path to match your system. Use `which php` in Terminal to find the correct path.

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

- **Date extraction:** EXIF `DateTimeOriginal` → `DateTime` → `FileDateTime`; `.mov`/`.mp4` always use file mtime. For `.raf` files, EXIF is read from the embedded JPEG preview, since PHP cannot parse the RAF container directly.
- **Duplicate handling:** If two files have the same datetime and size, the first gets the plain name, duplicates get `(001)`, `(002)`… suffixes.
- **Concurrency safety:** Per-directory `flock()` locking prevents race conditions when multiple Finder selections launch parallel instances.
- **Idempotency:** Already-renamed files are skipped on subsequent runs.
- **iCloud placeholder guard (macOS only):** Files stored in iCloud Drive with "Optimize Mac Storage" enabled may exist as lightweight placeholders locally, where only a Quick Look preview is kept and the full EXIF data is unavailable. The script detects this in two ways: (1) `mdls kMDItemIsDownloaded` where supported, and (2) a disk-usage heuristic — if a photo file (> 100 KB) has no EXIF date and less than 8 KB is actually allocated on disk, it is skipped with a message instead of being renamed to a zero-date name.

---

## Safety notes

- The script renames files **in place** and is **non-recursive**.
- Always test on a throwaway directory with copies before using it on real photo archives.
- If fixtures are missing for tests, regenerate them with: `php tests/generate_fixtures.php` (requires `magick`).

---

## License

This is a personal utility script. Use at your own risk.
