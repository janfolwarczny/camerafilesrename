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
const EXIF_MAKE = 'FUJIFILM';
const EXIF_MODEL = 'X-T5';
const LEICA_MAKE = 'Leica Camera AG';
const LEICA_MODEL = 'Leica Q3';

/** Injects a minimal EXIF APP1 segment with date, make, and model into a baseline JPEG. */
function injectExifData(string $jpegData, string $exifDateTime, string $make, string $model): string {
    if (substr($jpegData, 0, 2) !== "\xFF\xD8") {
        exit("Base image is not a JPEG.\n");
    }
    $dateTimeString = $exifDateTime . "\0"; // exactly 20 bytes
    $makeString = $make . "\0";
    $modelString = $model . "\0";

    $ifd0Offset = 8;
    $ifd0EntryCount = 3;
    $exifIfdOffset = $ifd0Offset + 2 + ($ifd0EntryCount * 12) + 4;
    $exifIfdSize = 2 + 12 + 4;
    $dataOffset = $exifIfdOffset + $exifIfdSize;
    $makeOffset = $dataOffset;
    $modelOffset = $makeOffset + strlen($makeString);
    $dateTimeOffset = $modelOffset + strlen($modelString);

    $tiff = 'II' . pack('v', 42) . pack('V', $ifd0Offset)
        . pack('v', $ifd0EntryCount)
        . pack('v', 0x010F) . pack('v', 2) . pack('V', strlen($makeString)) . pack('V', $makeOffset)
        . pack('v', 0x0110) . pack('v', 2) . pack('V', strlen($modelString)) . pack('V', $modelOffset)
        . pack('v', 0x8769) . pack('v', 4) . pack('V', 1) . pack('V', $exifIfdOffset)
        . pack('V', 0)
        . pack('v', 1)
        . pack('v', 0x9003) . pack('v', 2) . pack('V', strlen($dateTimeString)) . pack('V', $dateTimeOffset)
        . pack('V', 0)
        . $makeString . $modelString . $dateTimeString;
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
    injectExifData(file_get_contents($baseJpgPathname), EXIF_DATETIME_ORIGINAL, EXIF_MAKE, EXIF_MODEL)
);

// leica.jpg: the normalized Make is LEICACAMERAAG and the normalized Model contains
// LEICA, so the output should use LEICAQ3 without repeating the Make.
file_put_contents(
    $fixturesDir . '/leica.jpg',
    injectExifData(file_get_contents($baseJpgPathname), EXIF_DATETIME_ORIGINAL, LEICA_MAKE, LEICA_MODEL)
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
if (($exif['DateTimeOriginal'] ?? null) !== EXIF_DATETIME_ORIGINAL
    || ($exif['Make'] ?? null) !== EXIF_MAKE
    || ($exif['Model'] ?? null) !== EXIF_MODEL
) {
    exit("exif.jpg fixture is broken: DateTimeOriginal not readable.\n");
}

$leica = @exif_read_data($fixturesDir . '/leica.jpg') ?: [];
if (($leica['Make'] ?? null) !== LEICA_MAKE || ($leica['Model'] ?? null) !== LEICA_MODEL) {
    exit("leica.jpg fixture is broken: Make/Model not readable.\n");
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
