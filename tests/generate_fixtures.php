<?php
/**
 * Regenerates the binary fixtures in tests/fixtures/ used by run_tests.php.
 *
 * Requires ImageMagick (`magick`) for the JPEGs; the test suite itself does not.
 *
 * Usage: php tests/generate_fixtures.php
 */

$fixturesDir = __DIR__ . '/fixtures';
if (!is_dir($fixturesDir)) {
    mkdir($fixturesDir, 0777, true);
}

const EXIF_DATETIME_ORIGINAL = '2024:11:02 15:30:45';

/** Injects a minimal EXIF APP1 segment with DateTimeOriginal into a baseline JPEG. */
function injectExifDateTimeOriginal(string $jpegData, string $exifDateTime): string {
    if (substr($jpegData, 0, 2) !== "\xFF\xD8") {
        exit("Base image is not a JPEG.\n");
    }
    $dateTimeString = $exifDateTime . "\0"; // exactly 20 bytes
    $tiff = 'II' . pack('v', 42) . pack('V', 8)
        . pack('v', 1) . pack('v', 0x8769) . pack('v', 4) . pack('V', 1) . pack('V', 26) . pack('V', 0)
        . pack('v', 1) . pack('v', 0x9003) . pack('v', 2) . pack('V', 20) . pack('V', 44) . pack('V', 0)
        . $dateTimeString;
    $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;

    return "\xFF\xD8" . $app1 . substr($jpegData, 2);
}

// --- JPEGs -------------------------------------------------------------------

$baseJpgPathname = $fixturesDir . '/_base.jpg';
exec('magick -size 100x100 xc:red ' . escapeshellarg($baseJpgPathname), $output, $exitCode);
if ($exitCode !== 0 || !is_file($baseJpgPathname)) {
    exit("ImageMagick `magick` is required to generate fixtures.\n");
}

// plain.jpg: no EXIF date segments at all -> the script must fall back to FileDateTime.
copy($baseJpgPathname, $fixturesDir . '/plain.jpg');

// exif.jpg: real EXIF DateTimeOriginal -> the script must use it (timezone-independent).
file_put_contents(
    $fixturesDir . '/exif.jpg',
    injectExifDateTimeOriginal(file_get_contents($baseJpgPathname), EXIF_DATETIME_ORIGINAL)
);
unlink($baseJpgPathname);

// --- RAF with embedded JPEG preview ---------------------------------------------

// exif.raf: minimal Fuji RAF container (magic + directory at 0x54/0x58) wrapping
// exif.jpg as the embedded preview -> the script must read the date from the preview.
$jpegWithExif = file_get_contents($fixturesDir . '/exif.jpg');
$rafData = str_pad('FUJIFILMCCD-RAW 0201', 0x54, "\0")
    . pack('N2', 0x94, strlen($jpegWithExif));
$rafData = str_pad($rafData, 0x94, "\0") . $jpegWithExif;
file_put_contents($fixturesDir . '/exif.raf', $rafData);

// --- Fake camera files (content is irrelevant; only size and mtime matter) ----

file_put_contents($fixturesDir . '/image.raf', 'fakerafdata1');   // 12 bytes, unreadable EXIF -> zero prefix
file_put_contents($fixturesDir . '/image_small.raf', 'yy');       // 2 bytes
file_put_contents($fixturesDir . '/tiny.raf', 'x');               // 1 byte
file_put_contents($fixturesDir . '/video.mov', '0123456789');     // 10 bytes, mtime-based naming

// --- Verify the fixtures trigger the intended script behavior -----------------

$exif = @exif_read_data($fixturesDir . '/exif.jpg') ?: [];
if (($exif['DateTimeOriginal'] ?? null) !== EXIF_DATETIME_ORIGINAL) {
    exit("exif.jpg fixture is broken: DateTimeOriginal not readable.\n");
}

$plain = @exif_read_data($fixturesDir . '/plain.jpg') ?: [];
if (isset($plain['DateTimeOriginal']) || isset($plain['DateTime']) || !isset($plain['FileDateTime'])) {
    exit("plain.jpg fixture is broken: expected no EXIF date, only FileDateTime.\n");
}

$raf = @exif_read_data($fixturesDir . '/image.raf') ?: [];
if (isset($raf['DateTimeOriginal']) || isset($raf['DateTime']) || isset($raf['FileDateTime'])) {
    exit("image.raf fixture is broken: expected no readable date at all.\n");
}

$rafContainer = file_get_contents($fixturesDir . '/exif.raf');
$rafDirectory = unpack('Noffset/Nlength', substr($rafContainer, 0x54, 8));
$tmpJpeg = $fixturesDir . '/_rafcheck.jpg';
file_put_contents($tmpJpeg, substr($rafContainer, $rafDirectory['offset'], $rafDirectory['length']));
$rafExif = @exif_read_data($tmpJpeg) ?: [];
unlink($tmpJpeg);
if (($rafExif['DateTimeOriginal'] ?? null) !== EXIF_DATETIME_ORIGINAL) {
    exit("exif.raf fixture is broken: embedded JPEG DateTimeOriginal not readable.\n");
}

foreach (glob($fixturesDir . '/*') as $pathname) {
    echo basename($pathname) . ' (' . filesize($pathname) . " bytes)\n";
}
echo "Fixtures generated.\n";
