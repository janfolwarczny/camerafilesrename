<?php
/**
 * Test suite for camerafilesrename.php — no framework, no external services.
 *
 * Usage: php tests/run_tests.php
 *
 * Copies tests/fixtures/ into temp dirs under scenario filenames, runs the script
 * in directory mode and mixed file/directory mode, and checks rename behavior,
 * idempotency, collisions, and argument validation.
 * Temp dirs are always removed via a shutdown handler.
 *
 * If fixtures are missing, regenerate them first: php tests/generate_fixtures.php
 */

$scriptPath = dirname(__DIR__) . '/camerafilesrename.php';
$fixturesDir = __DIR__ . '/fixtures';

$fixtureNames = [
    'exif.jpg',
    'exif.raf',
    'leica.jpg',
    'subsec.jpg',
    'unique.jpg',
    'leica_frame.jpg',
    'xmp.jpg',
    'plain.jpg',
    'image.raf',
    'image_small.raf',
    'tiny.raf',
    'video.mov',
];
foreach ($fixtureNames as $name) {
    if (!is_file("$fixturesDir/$name")) {
        exit("Missing fixture tests/fixtures/$name — run: php tests/generate_fixtures.php\n");
    }
}
$fixtureSize = static fn(string $name): int => filesize("$fixturesDir/$name");

// Fixed mtimes so expected names are deterministic. Both this test and the script
// format timestamps in PHP's default timezone, so the assertions hold in any TZ.
const MTIME_PHOTO = 1730567445; // PHOTO.JPG / STILL.JPG — FileDateTime fallback path
const MTIME_LOWER = 1730567446; // lowercase.jpg — same fixture/size as PHOTO.JPG, must differ by a second
const MTIME_VIDEO = 1730561445; // .mov files — same second AND same size => intentional collision

$workDirs = [];
$removeRecursively = static function (string $path) use (&$removeRecursively): void {
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $child) {
            $removeRecursively("$path/$child");
        }
        rmdir($path);
    } elseif (is_file($path)) {
        unlink($path);
    }
};
register_shutdown_function(static function () use (&$workDirs, $removeRecursively): void {
    foreach ($workDirs as $workDir) {
        $removeRecursively($workDir);
    }
});

$makeDir = static function () use (&$workDirs): string {
    $dir = sys_get_temp_dir() . '/camerafilesrename_test_' . getmypid() . '_' . count($workDirs);
    mkdir($dir);
    $workDirs[] = $dir;

    return $dir;
};

// --- Helpers -----------------------------------------------------------------

$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS' : 'FAIL') . "  $label\n";
    if (!$condition) {
        $failures++;
    }
};

$runScript = static function (array $arguments) use ($scriptPath): array {
    $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $outputLines = [];
    exec("$command 2>&1", $outputLines, $exitCode);

    return [implode("\n", $outputLines), $exitCode];
};

$runScriptWithStdin = static function (array $arguments, string $stdinContent) use ($scriptPath): array {
    $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath);
    foreach ($arguments as $argument) {
        $command .= ' ' . escapeshellarg($argument);
    }
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if ($process === false) {
        return ['', 1];
    }
    fwrite($pipes[0], $stdinContent);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$stdout, $exitCode];
};

$listFiles = static function (string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $files = array_values(array_diff(scandir($dir), ['.', '..']));
    sort($files);

    return $files;
};

// Files except newly created collision logs (the pre-seeded old log is kept in the list).
$withoutNewLogs = static fn(array $files): array => array_values(array_filter(
    $files,
    static fn(string $file): bool => $file === '_camerafilesrename_1234567890.log'
        || !preg_match('/^_camerafilesrename_\d{10}\.log$/', $file)
));

// --- Expected names ----------------------------------------------------------

$cameraSuffix = '_FUJIFILMXT5';
$exifName = '20241102_153045_' . $fixtureSize('exif.jpg') . $cameraSuffix . '.jpeg';
$rafExifName = '20241102_153045_' . $fixtureSize('exif.raf') . $cameraSuffix . '.raf';
$leicaName = '20241102_153045_' . $fixtureSize('leica.jpg') . '_LEICAQ3.jpeg';
$photoName = date('Ymd_His', MTIME_PHOTO) . '_' . $fixtureSize('plain.jpg') . '.jpeg';
$lowerName = date('Ymd_His', MTIME_LOWER) . '_' . $fixtureSize('plain.jpg') . '.jpeg';
$videoName = date('Ymd_His', MTIME_VIDEO) . '_' . $fixtureSize('video.mov') . '.mov';
$videoName001 = preg_replace('/\.mov$/', '(001).mov', $videoName);
$videoName002 = preg_replace('/\.mov$/', '(002).mov', $videoName);
$photoName001 = preg_replace('/\.jpeg$/', '(001).jpeg', $photoName);
$migratedName = '00000000_000000_' . $fixtureSize('image.raf') . '.raf';
$zeroName = '00000000_000000_' . $fixtureSize('image_small.raf') . '.raf';
$tinyName = '20241102_153045_' . $fixtureSize('tiny.raf') . '.raf';
$subsecName = '20241102_153045_S128_' . $fixtureSize('subsec.jpg') . '_APPLEIPHONE7.jpeg';
$uniqueName = '20241102_153045_UABCDEF0123456789_' . $fixtureSize('unique.jpg') . '_DJIFC220.jpeg';
$leicaFrameName = '20241102_153045_F1021655_' . $fixtureSize('leica_frame.jpg') . '_LEICAQ3.jpeg';
$xmpName = '20241102_153045_X1234567890ABCDEF_' . $fixtureSize('xmp.jpg') . '_APPLEIPHONE17.jpeg';

// --- Run 1: directory mode, rename behavior ----------------------------------

$workDir = $makeDir();

copy("$fixturesDir/exif.jpg", "$workDir/EXIFSHOT.JPG");                        // EXIF date, uppercase ext
copy("$fixturesDir/plain.jpg", "$workDir/PHOTO.JPG");                          // FileDateTime fallback
touch("$workDir/PHOTO.JPG", MTIME_PHOTO);
copy("$fixturesDir/plain.jpg", "$workDir/lowercase.jpg");                      // all-lowercase source name
touch("$workDir/lowercase.jpg", MTIME_LOWER);
copy("$fixturesDir/video.mov", "$workDir/MOV_0001.mov");                       // colliding pair:
copy("$fixturesDir/video.mov", "$workDir/MOV_0002.mov");                       // same mtime, same size
touch("$workDir/MOV_0001.mov", MTIME_VIDEO);
touch("$workDir/MOV_0002.mov", MTIME_VIDEO);
copy("$fixturesDir/exif.raf", "$workDir/FUJIFILM.RAF");                        // EXIF in embedded JPEG preview
touch("$workDir/FUJIFILM.RAF", MTIME_VIDEO);                                   // wrong mtime: EXIF must win
copy("$fixturesDir/image.raf", "$workDir/20241102153045_DSCF1234.RAF");        // previous naming scheme
copy("$fixturesDir/image_small.raf", "$workDir/00000000_000000_12345.raf");    // zero prefix = re-process
copy("$fixturesDir/tiny.raf", "$workDir/$tinyName");                          // already renamed
file_put_contents("$workDir/_camerafilesrename_1234567890.log", "old log\n");        // old collision log
file_put_contents("$workDir/notes.txt", "not a camera file\n");                // unsupported extension

echo "--- Run 1: directory mode, rename ---\n";
[$output1, $exitCode1] = $runScript([$workDir]);
$files1 = $listFiles($workDir);

$check($exitCode1 === 0, 'directory mode runs without fatal error');
$check(in_array($exifName, $files1, true), "EXIF DateTimeOriginal + byte size + lowercase ext: $exifName");
$check(in_array($photoName, $files1, true), "FileDateTime (mtime) fallback: $photoName");
$check(in_array($lowerName, $files1, true), "all-lowercase source processed, no self-collision abort: $lowerName");
$check(in_array($videoName, $files1, true), "video named from mtime: $videoName");
$check(in_array($videoName001, $files1, true), "duplicate .mov gets (001) suffix: $videoName001");
$movLosers = array_intersect(['MOV_0001.mov', 'MOV_0002.mov'], $files1);
$check(count($movLosers) === 0, 'same-second + same-size collision: no source .mov left unrenamed');
$check(in_array($rafExifName, $files1, true), "RAF date read from embedded JPEG preview, not mtime: $rafExifName");
$check(in_array($migratedName, $files1, true), "previous scheme re-renamed: $migratedName");
$check(in_array($zeroName, $files1, true), "zero prefix stripped and re-processed: $zeroName");
$check(in_array($tinyName, $files1, true), 'already-renamed file left untouched');
$check(in_array('_camerafilesrename_1234567890.log', $files1, true), 'old collision log left untouched');
$check(in_array('notes.txt', $files1, true), 'unsupported extension left untouched');
$check(str_contains($output1, 'Already renamed.'), 'stdout reports "Already renamed."');
$check(str_contains($output1, 'Not matching original filename.'), 'stdout reports non-matching file');
$check(!str_contains($output1, 'already exists.'), 'no "already exists" error in first run');

$newLogs = array_values(array_filter(
    $files1,
    static fn(string $file): bool => $file !== '_camerafilesrename_1234567890.log'
        && preg_match('/^_camerafilesrename_\d{10}\.log$/', $file)
));
$check($newLogs === [], 'no new collision logs created for within-run collisions');

// --- Run 2: directory mode, idempotency --------------------------------------

echo "--- Run 2: directory mode, idempotency ---\n";
[$output2, $exitCode2] = $runScript([$workDir]);
$files2 = $listFiles($workDir);

$check($exitCode2 === 0, 'second directory run completes');
$check($withoutNewLogs($files2) === $withoutNewLogs($files1), 'second directory run changes no file names');
$check(
    substr_count($output2, 'Already renamed.') === 7,
    'second directory run skips all seven dated files as already renamed'
);

// A previous run may have produced a zero-date formatted name before metadata
// support was available. It must be treated as a migration candidate and
// rebuilt from the file's current EXIF data.
$historicalZeroDir = $makeDir();
$historicalZeroExifName = '00000000_000000_' . $fixtureSize('exif.jpg') . '.jpg';
copy("$fixturesDir/exif.jpg", "$historicalZeroDir/$historicalZeroExifName");

echo "--- Run 2b: repair historical zero-date name ---\n";
[$output2b, $exitCode2b] = $runScript([$historicalZeroDir]);
$files2b = $listFiles($historicalZeroDir);
$check($exitCode2b === 0, 'historical zero-date name repair exits 0');
$check(is_file("$historicalZeroDir/$exifName"), "historical zero-date name is rebuilt from EXIF: $exifName");
$check(!is_file("$historicalZeroDir/$historicalZeroExifName"), 'historical zero-date source name is removed');

// --- Run 3: file arguments -----------------------------------------------------

$filesDir = $makeDir();

// Single file argument.
copy("$fixturesDir/exif.jpg", "$filesDir/ONESHOT.JPG");

echo "--- Run 3a: single file argument ---\n";
[$output3a, $exitCode3a] = $runScript(["$filesDir/ONESHOT.JPG"]);
$check($exitCode3a === 0, 'single file argument accepted');
$check(is_file("$filesDir/$exifName"), "single file renamed: $exifName");
$check(!file_exists("$filesDir/ONESHOT.JPG"), 'original single file removed');

// List of files in the same directory (with collision and existing-target guard).
copy("$fixturesDir/video.mov", "$filesDir/CLIP_A.mov");
copy("$fixturesDir/video.mov", "$filesDir/CLIP_B.mov");
touch("$filesDir/CLIP_A.mov", MTIME_VIDEO);
touch("$filesDir/CLIP_B.mov", MTIME_VIDEO);
copy("$fixturesDir/plain.jpg", "$filesDir/STILL.JPG");
touch("$filesDir/STILL.JPG", MTIME_PHOTO);

echo "--- Run 3b: list of files in same directory ---\n";
[$output3b, $exitCode3b] = $runScript([
    "$filesDir/CLIP_A.mov",
    "$filesDir/CLIP_B.mov",
    "$filesDir/STILL.JPG",
]);
$files3b = $listFiles($filesDir);
$check($exitCode3b === 0, 'file-list mode runs without fatal error');
$check(in_array($videoName, $files3b, true), 'listed video renamed from mtime');
$check(in_array($videoName001, $files3b, true), 'listed duplicate .mov gets (001) suffix');
$movLosers3b = array_intersect(['CLIP_A.mov', 'CLIP_B.mov'], $files3b);
$check(count($movLosers3b) === 0, 'listed .mov collision leaves no source untouched');
$check(in_array($photoName, $files3b, true), 'listed .jpg renamed as .jpeg');
$check(!str_contains($output3b, 'already exists.'), 'no collision error in file-list mode for suffix rename');
$check(glob("$filesDir/_camerafilesrename_*.log") === [], 'no collision log written next to listed files');

// Existing-target guard: GUARD.JPG would collide with the STILL.JPG target already on disk.
copy("$fixturesDir/plain.jpg", "$filesDir/GUARD.JPG");
touch("$filesDir/GUARD.JPG", MTIME_PHOTO);

echo "--- Run 3c: existing-target guard in file-list mode ---\n";
[$output3c, $exitCode3c] = $runScript(["$filesDir/GUARD.JPG"]);
$check($exitCode3c === 0, 'file-list mode exits cleanly on existing-target guard');
$check(is_file("$filesDir/$photoName001"), "GUARD.JPG renamed with (001) suffix: $photoName001");
$check(!file_exists("$filesDir/GUARD.JPG"), 'GUARD.JPG removed after rename');
$check(!str_contains($output3c, 'already exists.'), 'no collision error for suffix rename of existing target');

// Multiple directories in one run: same target name, different directories, no collision.
$filesDirSub1 = $filesDir . '/sub1';
$filesDirSub2 = $filesDir . '/sub2';
mkdir($filesDirSub1);
mkdir($filesDirSub2);
copy("$fixturesDir/video.mov", "$filesDirSub1/CAM_A.mov");
copy("$fixturesDir/video.mov", "$filesDirSub2/CAM_B.mov");
touch("$filesDirSub1/CAM_A.mov", MTIME_VIDEO);
touch("$filesDirSub2/CAM_B.mov", MTIME_VIDEO);

echo "--- Run 3d: file arguments across directories ---\n";
[$output3d, $exitCode3d] = $runScript(["$filesDirSub1/CAM_A.mov", "$filesDirSub2/CAM_B.mov"]);
$check($exitCode3d === 0, 'file arguments across directories run without fatal error');
$check(!str_contains($output3d, 'Error'), 'no error output for multi-directory run');
$check(is_file("$filesDirSub1/$videoName") && is_file("$filesDirSub2/$videoName"), 'same target name allowed in different directories');
$check(glob("$filesDirSub1/_camerafilesrename_*.log") === [] && glob("$filesDirSub2/_camerafilesrename_*.log") === [], 'no collision logs in either directory');

// Duplicate file argument should be deduped and processed once.
$existingTarget = realpath("$filesDirSub1/$videoName") ?: "$filesDirSub1/$videoName";

echo "--- Run 3e: duplicate file argument ---\n";
[$output3e, $exitCode3e] = $runScript([$existingTarget, $existingTarget]);
$check($exitCode3e === 0, 'duplicate file argument accepted');
$check(!str_contains($output3e, 'Error'), 'duplicate file argument is not treated as a collision');
$check(substr_count($output3e, 'Already renamed.') === 1, 'duplicate file argument is processed once');

// --- Run 4: argument validation ----------------------------------------------

echo "--- Run 4: argument validation ---\n";

[$output4a, $exitCode4a] = $runScript([]);
$check($exitCode4a === 1 && str_contains($output4a, 'Usage'), 'no arguments prints usage and exits 1');

[$output4b, $exitCode4b] = $runScript([$filesDir . '/NOPE.jpg']);
$check($exitCode4b === 1 && str_contains($output4b, 'not found'), 'missing path argument exits 1');

// --- Run 5: mixed directories and files --------------------------------------

$mixedDir = $makeDir();
mkdir("$mixedDir/dirA");
mkdir("$mixedDir/dirB");
mkdir("$mixedDir/dirC");

copy("$fixturesDir/plain.jpg", "$mixedDir/dirA/IN_DIR_A.JPG");
touch("$mixedDir/dirA/IN_DIR_A.JPG", MTIME_PHOTO);
copy("$fixturesDir/plain.jpg", "$mixedDir/dirB/IN_DIR_B.JPG");
touch("$mixedDir/dirB/IN_DIR_B.JPG", MTIME_LOWER);
copy("$fixturesDir/plain.jpg", "$mixedDir/dirC/OUTSIDE.JPG");
touch("$mixedDir/dirC/OUTSIDE.JPG", MTIME_VIDEO);

echo "--- Run 5: mixed directories and files ---\n";
[$output5, $exitCode5] = $runScript([
    "$mixedDir/dirA",
    "$mixedDir/dirC/OUTSIDE.JPG",
    "$mixedDir/dirB",
]);
$check($exitCode5 === 0, 'mixed directories and files accepted');
$check(!str_contains($output5, 'Error'), 'no errors in mixed run');
$outsideName = date('Ymd_His', MTIME_VIDEO) . '_' . $fixtureSize('plain.jpg') . '.jpeg';
$check(is_file("$mixedDir/dirA/$photoName"), 'file in first scanned directory renamed');
$check(is_file("$mixedDir/dirB/$lowerName"), 'file in second scanned directory renamed');
$check(is_file("$mixedDir/dirC/$outsideName"), 'individual file argument renamed');

// Directory plus a file inside it should be deduped and processed once.
$dedupDir = $makeDir();
mkdir("$dedupDir/dir");
copy("$fixturesDir/plain.jpg", "$dedupDir/dir/BOTH.JPG");
touch("$dedupDir/dir/BOTH.JPG", MTIME_PHOTO);

echo "--- Run 5b: directory plus file inside it ---\n";
[$output5b, $exitCode5b] = $runScript(["$dedupDir/dir", "$dedupDir/dir/BOTH.JPG"]);
$check($exitCode5b === 0, 'directory plus file inside it accepted');
$check(!str_contains($output5b, 'Error'), 'no duplicate-processing error');
$check(substr_count($output5b, 'BOTH.JPG') === 1, 'file inside directory mentioned only once');
$check(is_file("$dedupDir/dir/$photoName"), 'file inside directory renamed exactly once');

// --- Run 6: suffix collision edge cases --------------------------------------

// 6a: Triple collision — three files with same mtime and size.
$tripleDir = $makeDir();
copy("$fixturesDir/video.mov", "$tripleDir/TRIPLE_A.mov");
copy("$fixturesDir/video.mov", "$tripleDir/TRIPLE_B.mov");
copy("$fixturesDir/video.mov", "$tripleDir/TRIPLE_C.mov");
touch("$tripleDir/TRIPLE_A.mov", MTIME_VIDEO);
touch("$tripleDir/TRIPLE_B.mov", MTIME_VIDEO);
touch("$tripleDir/TRIPLE_C.mov", MTIME_VIDEO);

echo "--- Run 6a: triple collision ---\n";
[$output6a, $exitCode6a] = $runScript([$tripleDir]);
$files6a = $listFiles($tripleDir);
$check($exitCode6a === 0, 'triple collision run completes');
$check(in_array($videoName, $files6a, true), 'triple collision: first .mov gets plain name');
$check(in_array($videoName001, $files6a, true), 'triple collision: second .mov gets (001) suffix');
$check(in_array($videoName002, $files6a, true), 'triple collision: third .mov gets (002) suffix');
$check(
    array_intersect(['TRIPLE_A.mov', 'TRIPLE_B.mov', 'TRIPLE_C.mov'], $files6a) === [],
    'triple collision: no source .mov left unrenamed'
);

// 6b: Existing suffixed file on disk from a previous run.
$existingSuffixDir = $makeDir();
copy("$fixturesDir/video.mov", "$existingSuffixDir/PREVIOUS.mov");
// Simulate a previous run that left (001) behind.
rename("$existingSuffixDir/PREVIOUS.mov", "$existingSuffixDir/$videoName001");
copy("$fixturesDir/video.mov", "$existingSuffixDir/NEW_A.mov");
copy("$fixturesDir/video.mov", "$existingSuffixDir/NEW_B.mov");
touch("$existingSuffixDir/NEW_A.mov", MTIME_VIDEO);
touch("$existingSuffixDir/NEW_B.mov", MTIME_VIDEO);

echo "--- Run 6b: existing suffixed file on disk ---\n";
[$output6b, $exitCode6b] = $runScript([$existingSuffixDir]);
$files6b = $listFiles($existingSuffixDir);
$check($exitCode6b === 0, 'existing suffix run completes');
$check(in_array($videoName, $files6b, true), 'existing suffix: first new .mov gets plain name');
$check(in_array($videoName001, $files6b, true), 'existing suffix: pre-existing (001) file untouched');
$check(in_array($videoName002, $files6b, true), 'existing suffix: second new .mov gets (002) suffix');
$check(
    array_intersect(['NEW_A.mov', 'NEW_B.mov'], $files6b) === [],
    'existing suffix: no new source .mov left unrenamed'
);

// 6c: Idempotency — suffixed files are recognized as already renamed.
echo "--- Run 6c: idempotency of suffixed files ---\n";
[$output6c, $exitCode6c] = $runScript([$tripleDir]);
$files6c = $listFiles($tripleDir);
$check($exitCode6c === 0, 'suffix idempotency run completes');
$check($files6c === $files6a, 'suffix idempotency: no file names changed');
$check(
    substr_count($output6c, 'Already renamed.') === 3,
    'suffix idempotency: all three suffixed .mov files skipped as already renamed'
);

// --- Run 7: concurrent Quick Action simulation -------------------------------

// When multiple files are selected in Finder and a Shortcut runs this script
// on each file in parallel, per-directory locking must prevent overwrites.
$concurrentDir = $makeDir();
copy("$fixturesDir/video.mov", "$concurrentDir/PARALLEL_A.mov");
copy("$fixturesDir/video.mov", "$concurrentDir/PARALLEL_B.mov");
copy("$fixturesDir/video.mov", "$concurrentDir/PARALLEL_C.mov");
touch("$concurrentDir/PARALLEL_A.mov", MTIME_VIDEO);
touch("$concurrentDir/PARALLEL_B.mov", MTIME_VIDEO);
touch("$concurrentDir/PARALLEL_C.mov", MTIME_VIDEO);

$runScriptAsync = static function (string $argument) use ($scriptPath): array {
    $command = PHP_BINARY . ' ' . escapeshellarg($scriptPath) . ' ' . escapeshellarg($argument);
    $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
    if ($process === false) {
        return ['', 1];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$stdout, $exitCode];
};

echo "--- Run 7: concurrent Quick Action simulation ---\n";
// Launch three parallel processes, each with a single file argument.
$parallelA = $runScriptAsync("$concurrentDir/PARALLEL_A.mov");
$parallelB = $runScriptAsync("$concurrentDir/PARALLEL_B.mov");
$parallelC = $runScriptAsync("$concurrentDir/PARALLEL_C.mov");

$files7 = $listFiles($concurrentDir);
$renamedFiles7 = array_values(array_filter($files7, static fn(string $f): bool => !str_starts_with($f, '.')));

$check(
    $parallelA[1] === 0 && $parallelB[1] === 0 && $parallelC[1] === 0,
    'all three concurrent processes exit 0'
);
$check(
    in_array($videoName, $renamedFiles7, true),
    'concurrent run: first .mov gets plain name'
);
$check(
    in_array($videoName001, $renamedFiles7, true),
    'concurrent run: second .mov gets (001) suffix'
);
$check(
    in_array($videoName002, $renamedFiles7, true),
    'concurrent run: third .mov gets (002) suffix'
);
$check(
    array_intersect(['PARALLEL_A.mov', 'PARALLEL_B.mov', 'PARALLEL_C.mov'], $renamedFiles7) === [],
    'concurrent run: no original source .mov left unrenamed'
);
$check(
    count($renamedFiles7) === 3,
    'concurrent run: exactly three renamed .mov files, none lost'
);

// --- Run 8: debug mode ---------------------------------------------------------

$debugDir = $makeDir();
copy("$fixturesDir/exif.jpg", "$debugDir/DEBUG.JPG");

echo "--- Run 8: debug mode ---\n";
[$output8, $exitCode8] = $runScript(['--debug', $debugDir]);
$files8 = $listFiles($debugDir);
$debugFiles = array_values(array_filter($files8, static fn(string $f): bool => str_starts_with($f, '_camerafilesrename_debug_')));
$check($exitCode8 === 0, 'debug mode runs without error');
$check(count($debugFiles) === 1, 'debug mode creates exactly one debug file');
$debugContent = $debugFiles ? (string) file_get_contents("$debugDir/{$debugFiles[0]}") : '';
$check(
    str_contains($debugContent, 'Arguments:'),
    'debug file contains "Arguments:" header'
);
$check(
    str_contains($debugContent, '--debug'),
    'debug file contains the --debug flag'
);
$check(
    str_contains($debugContent, 'Paths:'),
    'debug file contains "Paths:" header'
);
$check(
    str_contains($debugContent, 'DEBUG.JPG'),
    'debug file contains the processed filename'
);
$check(
    str_contains($debugContent, 'Inputs:'),
    'debug file contains "Inputs:" header'
);
$check(
    str_contains($debugContent, '1 file renamed'),
    'debug file contains the summary output'
);

// --- Run 9: stdin mode ---------------------------------------------------------

// 9a: Directory path via stdin.
$stdinDir = $makeDir();
copy("$fixturesDir/exif.jpg", "$stdinDir/STDIN_DIR.JPG");

echo "--- Run 9a: stdin with directory ---\n";
[$output9a, $exitCode9a] = $runScriptWithStdin([], $stdinDir . "\n");
$files9a = $listFiles($stdinDir);
$check($exitCode9a === 0, 'stdin directory mode exits 0');
$check(in_array($exifName, $files9a, true), 'stdin directory: file renamed');

// 9b: File path via stdin.
$stdinFileDir = $makeDir();
copy("$fixturesDir/plain.jpg", "$stdinFileDir/STDIN_FILE.JPG");
touch("$stdinFileDir/STDIN_FILE.JPG", MTIME_PHOTO);

echo "--- Run 9b: stdin with file ---\n";
[$output9b, $exitCode9b] = $runScriptWithStdin([], $stdinFileDir . "/STDIN_FILE.JPG\n");
$files9b = $listFiles($stdinFileDir);
$check($exitCode9b === 0, 'stdin file mode exits 0');
$check(in_array($photoName, $files9b, true), 'stdin file: file renamed');

// 9c: Arguments present — stdin should be ignored.
$stdinIgnoreDir = $makeDir();
copy("$fixturesDir/exif.jpg", "$stdinIgnoreDir/ARG.JPG");
copy("$fixturesDir/plain.jpg", "$stdinIgnoreDir/STDIN_IGNORE.JPG");
touch("$stdinIgnoreDir/STDIN_IGNORE.JPG", MTIME_PHOTO);

echo "--- Run 9c: arguments present, stdin ignored ---\n";
[$output9c, $exitCode9c] = $runScriptWithStdin(["$stdinIgnoreDir/ARG.JPG"], $stdinIgnoreDir . "/STDIN_IGNORE.JPG\n");
$files9c = $listFiles($stdinIgnoreDir);
$check($exitCode9c === 0, 'args+stdin mode exits 0');
$check(in_array($exifName, $files9c, true), 'args+stdin: arg file renamed');
$check(in_array('STDIN_IGNORE.JPG', $files9c, true), 'args+stdin: stdin file left untouched');

// --- Run 10: iCloud placeholder guard -----------------------------------------

$icloudDir = $makeDir();
copy("$fixturesDir/exif.jpg", "$icloudDir/CLOUD.JPG");

echo "--- Run 10: iCloud placeholder guard ---\n";
[$output10, $exitCode10] = $runScript([$icloudDir]);
$files10 = $listFiles($icloudDir);
$check($exitCode10 === 0, 'iCloud guard: run exits 0');
$check(in_array($exifName, $files10, true), 'iCloud guard: regular local file is renamed');
$check(
    !str_contains($output10, 'iCloud file not fully downloaded'),
    'iCloud guard: no iCloud skip message for regular local files'
);

// --- Run 11: add camera suffix to an existing date/size name ------------------

$cameraMigrationDir = $makeDir();
$formattedWithoutCameraName = '20241102_153045_' . $fixtureSize('exif.jpg') . '.jpg';
copy("$fixturesDir/exif.jpg", "$cameraMigrationDir/$formattedWithoutCameraName");

echo "--- Run 11: add camera suffix to an existing formatted name ---\n";
[$output11, $exitCode11] = $runScript([$cameraMigrationDir]);
$files11 = $listFiles($cameraMigrationDir);
$check($exitCode11 === 0, 'formatted name with EXIF camera metadata exits 0');
$check(is_file("$cameraMigrationDir/$exifName"), "formatted name gets camera suffix: $exifName");
$check(!is_file("$cameraMigrationDir/$formattedWithoutCameraName"), 'formatted name without camera suffix is replaced');

[$output11b, $exitCode11b] = $runScript([$cameraMigrationDir]);
$check($exitCode11b === 0, 'camera-suffixed migrated name is idempotent');
$check(substr_count($output11b, 'Already renamed.') === 1, 'camera-suffixed migrated name is skipped on the next run');

// --- Run 12: changed file size invalidates an existing formatted name --------

$sizeChangedDir = $makeDir();
$originalSize = $fixtureSize('exif.jpg');
$changedSize = $originalSize + 1;
$sizeChangedSourceName = '20241102_153045_' . $originalSize . $cameraSuffix . '.jpg';
$sizeChangedTargetName = '20241102_153045_' . $changedSize . $cameraSuffix . '.jpeg';
copy("$fixturesDir/exif.jpg", "$sizeChangedDir/$sizeChangedSourceName");
file_put_contents("$sizeChangedDir/$sizeChangedSourceName", "x", FILE_APPEND);

echo "--- Run 12: changed file size ---\n";
[$output12, $exitCode12] = $runScript([$sizeChangedDir]);
$check($exitCode12 === 0, 'changed-size formatted file exits 0');
$check(is_file("$sizeChangedDir/$sizeChangedTargetName"), "changed-size file gets the current size: $sizeChangedTargetName");
$check(!is_file("$sizeChangedDir/$sizeChangedSourceName"), 'changed-size old filename is replaced');

// --- Run 13: Leica Camera Make is not repeated in the Model suffix ------------

$leicaDir = $makeDir();
copy("$fixturesDir/leica.jpg", "$leicaDir/LEICA.JPG");

echo "--- Run 13: Leica Camera Make/Model normalization ---\n";
[$output13, $exitCode13] = $runScript([$leicaDir]);
$check($exitCode13 === 0, 'Leica Make/Model run exits 0');
$check(is_file("$leicaDir/$leicaName"), "Leica Make is omitted when Model contains LEICA: $leicaName");

// --- Run 14: metadata identity priority and legacy migration -----------------

$identityDir = $makeDir();
copy("$fixturesDir/subsec.jpg", "$identityDir/SUBSEC.JPG");
copy("$fixturesDir/unique.jpg", "$identityDir/UNIQUE.JPG");
copy("$fixturesDir/leica_frame.jpg", "$identityDir/LEICA_FRAME.JPG");
copy("$fixturesDir/xmp.jpg", "$identityDir/XMP.JPG");

echo "--- Run 14: metadata identity priority ---\n";
[$output14, $exitCode14] = $runScript([$identityDir]);
$files14 = $listFiles($identityDir);
$check($exitCode14 === 0, 'metadata identity run exits 0');
$check(in_array($subsecName, $files14, true), 'SubSecTimeOriginal is preferred over ImageUniqueID');
$check(in_array($uniqueName, $files14, true), 'ImageUniqueID is used when sub-second time is absent');
$check(in_array($leicaFrameName, $files14, true), 'Leica source frame number is used when EXIF IDs are absent');
$check(in_array($xmpName, $files14, true), 'XMP document ID is used as the last metadata fallback');
$check(
    array_intersect(['SUBSEC.JPG', 'UNIQUE.JPG', 'LEICA_FRAME.JPG', 'XMP.JPG'], $files14) === [],
    'metadata identity run removes all source files'
);

[$output14b, $exitCode14b] = $runScript([$identityDir]);
$files14b = $listFiles($identityDir);
$check($exitCode14b === 0, 'metadata identity idempotency run exits 0');
$check($files14b === $files14, 'metadata identity names remain stable on the next run');
$check(substr_count($output14b, 'Already renamed.') === 4, 'metadata identity files are skipped on the next run');

$identityMigrationDir = $makeDir();
$legacyIdentityName = '20241102_153045_' . $fixtureSize('subsec.jpg') . '_APPLEIPHONE7.jpg';
copy("$fixturesDir/subsec.jpg", "$identityMigrationDir/$legacyIdentityName");

echo "--- Run 14b: add metadata identity to an existing formatted name ---\n";
[$output14c, $exitCode14c] = $runScript([$identityMigrationDir]);
$files14c = $listFiles($identityMigrationDir);
$check($exitCode14c === 0, 'formatted name without identity exits 0');
$check(is_file("$identityMigrationDir/$subsecName"), "formatted name gets metadata identity: $subsecName");
$check(!is_file("$identityMigrationDir/$legacyIdentityName"), 'formatted name without identity is replaced');

// --- Summary -------------------------------------------------------------------

if ($failures > 0) {
    echo "\n--- captured outputs ---\n";
    echo "run 1:\n$output1\n";
    echo "run 2:\n$output2\n";
    echo "run 3a:\n$output3a\n";
    echo "run 3b:\n$output3b\n";
    echo "run 3c:\n$output3c\n";
    echo "run 3d:\n$output3d\n";
    echo "run 3e:\n$output3e\n";
    echo "run 4a:\n$output4a\n";
    echo "run 4b:\n$output4b\n";
    echo "run 5:\n$output5\n";
    echo "run 5b:\n$output5b\n";
    echo "run 6a:\n$output6a\n";
    echo "run 6b:\n$output6b\n";
    echo "run 6c:\n$output6c\n";
    echo "run 7:\n" . implode("\n", [$parallelA[0], $parallelB[0], $parallelC[0]]) . "\n";
    echo "run 8:\n$output8\n";
    echo "run 9a:\n$output9a\n";
    echo "run 9b:\n$output9b\n";
    echo "run 9c:\n$output9c\n";
    echo "run 10:\n$output10\n";
    echo "run 11:\n$output11\n";
    echo "run 11b:\n$output11b\n";
    echo "run 12:\n$output12\n";
    echo "run 13:\n$output13\n";
    echo "run 14:\n$output14\n";
    echo "run 14b:\n$output14b\n";
    echo "run 14c:\n$output14c\n";
    echo "FAILED: $failures assertion(s)\n";
    exit(1);
}
echo "OK: all assertions passed\n";
