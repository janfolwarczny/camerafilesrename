<?php


(new class() {

    private const RENAMED_FILENAME_PATTERN = '/^[0-9]{8}_[0-9]{6}(?:_(?<identity>S[0-9]{3,9}|F[0-9]+|U[A-Z0-9]+|X[A-Z0-9]+))?_(?<size>[0-9]+)(?:_(?<camera>(?!DUP[0-9]{3}\.)[A-Z0-9]+))?(?:_DUP[0-9]{3})?\.(?<extension>dng|jpg|jpeg|heic|raf|mov|mp4)$/i';

    private const ORIGINAL_FILENAME_PATTERN = '/^[A-Z0-9_\-]+(\([0-9]{3}\))?( [0-9]+)?\.(dng|jpg|jpeg|raf|heic|mov|mp4)$/i';

    private const VIDEO_FILENAME_PATTERN = '/\.(mov|mp4)$/i';

    private const LOG_FILENAME_PATTERN = '/^_camerafilesrename_[0-9]{10}\.log$/i';

    private const PROGRESS_PREFIX = '__CAMERAFILESRENAME_PROGRESS__';

    private const DATETIME_FORMAT = 'Ymd_His';

    /** @var SplFileInfo[] */
    private array $files = [];

    /** @var array<string, bool> */
    private array $handledSidecars = [];

    private int $runTimestamp;

    private int $renamedCount = 0;

    private int $skippedCount = 0;

    private bool $debugEnabled = false;

    private bool $progressEnabled = false;

    private int $progressTotal = 0;

    private int $progressCompleted = 0;



    public function __invoke(): void {
        global $argv;
        $arguments = array_slice($argv, 1);
        $this->debugEnabled = in_array('--debug', $arguments, true);
        $this->progressEnabled = in_array('--progress', $arguments, true);
        if ($this->debugEnabled || $this->progressEnabled) {
            $arguments = array_values(array_diff($arguments, ['--debug', '--progress']));
        }

        $inputs = $arguments;
        if (!$inputs) {
            $isTerminal = function_exists('posix_isatty') && posix_isatty(STDIN);
            if (!$isTerminal) {
                while (($line = fgets(STDIN)) !== false) {
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $inputs[] = $trimmed;
                    }
                }
            }
        }

        if (!$inputs) {
            fwrite(STDERR, 'Usage: php camerafilesrename.php <directory|file> [<directory|file> ...]' . PHP_EOL);
            exit(1);
        }
        $this->runTimestamp = time();

        $pathnames = [];
        $firstDir = null;
        foreach ($inputs as $input) {
            $realPath = realpath($input);
            if ($realPath === false) {
                fwrite(STDERR, "Error: Path $input not found." . PHP_EOL);
                exit(1);
            }
            if (is_dir($realPath)) {
                $firstDir ??= $realPath;
                foreach (new FilesystemIterator($realPath, FilesystemIterator::SKIP_DOTS) as $fileInfo) {
                    $pathnames[] = $fileInfo->getPathname();
                }
            } elseif (is_file($realPath)) {
                $firstDir ??= dirname($realPath);
                $pathnames[] = $realPath;
            } else {
                fwrite(STDERR, "Error: Path $input is not a file or directory." . PHP_EOL);
                exit(1);
            }
        }

        $debugHandle = null;
        if ($this->debugEnabled && $firstDir !== null) {
            $debugFile = $firstDir . '/_camerafilesrename_debug_' . date('Ymd_His') . '.txt';
            $debugHandle = @fopen($debugFile, 'w');
            if ($debugHandle !== false) {
                fwrite($debugHandle, 'Arguments: ' . implode(' ', $argv) . PHP_EOL);
                fwrite($debugHandle, 'Inputs: ' . implode(' ', $inputs) . PHP_EOL);
                fwrite($debugHandle, 'Paths: ' . implode(' ', $pathnames) . PHP_EOL . PHP_EOL);
                ob_start();
                register_shutdown_function(function () use ($debugHandle) {
                    if (ob_get_level() > 0) {
                        $output = ob_get_flush();
                        fwrite($debugHandle, $output);
                    }
                    fclose($debugHandle);
                });
            }
        }

        $groups = [];
        foreach ($pathnames as $pathname) {
            $groups[dirname($pathname)][$pathname] = $pathname;
        }
        $this->initializeProgress($groups);
        foreach ($groups as $groupPathnames) {
            $this->processGroup(array_values($groupPathnames));
        }

        $total = $this->renamedCount + $this->skippedCount;
        if ($total === 0) {
            echo 'Nothing to rename' . PHP_EOL;
        } elseif ($this->skippedCount === 0) {
            echo $this->renamedCount . ' file' . ($this->renamedCount === 1 ? '' : 's') . ' renamed' . PHP_EOL;
        } else {
            echo $this->renamedCount . ' renamed, ' . $this->skippedCount . ' skipped' . PHP_EOL;
        }
    }





    private function processGroup(array $pathnames): void {
        $dir = dirname($pathnames[0]);
        $lockFile = $dir . '/._camerafilesrename.lock';
        $lockHandle = @fopen($lockFile, 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX)) {
            exit("Error: Could not acquire lock for $dir." . PHP_EOL);
        }

        try {
            $this->files = [];
            $this->handledSidecars = [];
            foreach ($pathnames as $pathname) {
                $file = new SplFileInfo($pathname);
                $filename = $file->getFilename();
                if (preg_match(self::LOG_FILENAME_PATTERN, $filename)) {
                    continue;
                }
                $lowerFilename = strtolower($filename);
                if (array_key_exists($lowerFilename, $this->files)) {
                    flock($lockHandle, LOCK_UN);
                    fclose($lockHandle);
                    exit("Error: Lower filename for $filename already exist." . PHP_EOL);
                }
                $this->files[$lowerFilename] = $file;
            }

            // Process image/video files before sidecars so a sidecar selected as part of a
            // directory is moved with its image instead of being reported as unsupported first.
            $groupFiles = array_values($this->files);
            $imageFiles = array_values(array_filter(
                $groupFiles,
                static fn(SplFileInfo $file): bool => strtolower($file->getExtension()) !== 'xmp'
            ));
            $sidecarFiles = array_values(array_filter(
                $groupFiles,
                static fn(SplFileInfo $file): bool => strtolower($file->getExtension()) === 'xmp'
            ));
            $groupFiles = array_merge($imageFiles, $sidecarFiles);
            $regularFileFlags = array_map(
                static fn(SplFileInfo $file): bool => $file->isFile(),
                $groupFiles
            );

            foreach ($groupFiles as $index => $file) {
                $isRegularFile = $regularFileFlags[$index];
                if ($this->processFile($file)) {
                    $this->renamedCount++;
                } else {
                    $this->skippedCount++;
                }
                $this->reportProgress($file, $isRegularFile);
            }

            ksort($this->files);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }



    private function lowerFilenameExist(string $filename): bool {
        return array_key_exists(strtolower($filename), $this->files);
    }



    private function isHandledSidecar(SplFileInfo $file): bool {
        return $this->handledSidecars[strtolower($file->getPathname())] ?? false;
    }



    /**
     * Finds an exact case-insensitive filename in the current group or on disk.
     * File-list mode intentionally indexes only selected files, so the directory
     * scan is needed to find an unselected sidecar and to guard its target.
     */
    private function findFileByLowerFilename(string $directory, string $filename): ?SplFileInfo {
        $lowerFilename = strtolower($filename);
        if (array_key_exists($lowerFilename, $this->files)) {
            $file = $this->files[$lowerFilename];
            if ($file->isFile()) {
                return $file;
            }
        }

        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($file->isFile() && strtolower($file->getFilename()) === $lowerFilename) {
                return $file;
            }
        }

        return null;
    }



    private function findXmpSidecar(SplFileInfo $image): ?SplFileInfo {
        $imageStem = pathinfo($image->getFilename(), PATHINFO_FILENAME);
        $sidecarFilename = $imageStem . '.xmp';

        return $this->findFileByLowerFilename($image->getPath(), $sidecarFilename);
    }



    /**
     * Renames the image's XMP sidecar to the same generated basename.
     * Returns true only when a sidecar was moved; an already matching sidecar
     * is marked handled but does not count as a rename.
     */
    private function renameXmpSidecar(SplFileInfo $image, string $newImageFilename): bool {
        $sidecar = $this->findXmpSidecar($image);
        if ($sidecar === null) {
            return false;
        }

        $sourcePathname = $sidecar->getPathname();
        $sourceRealPath = $sidecar->getRealPath();
        $targetFilename = pathinfo($newImageFilename, PATHINFO_FILENAME) . '.xmp';
        $targetPathname = $image->getPath() . '/' . $targetFilename;
        $existingTarget = $this->findFileByLowerFilename($image->getPath(), $targetFilename);

        if ($existingTarget !== null) {
            $existingRealPath = $existingTarget->getRealPath();
            if ($sourceRealPath !== false && $existingRealPath === $sourceRealPath) {
                $this->handledSidecars[strtolower($sourcePathname)] = true;

                return false;
            }

            $message = "Error: New sidecar filename $targetFilename for {$image->getFilename()} already exists." . PHP_EOL;
            /** @noinspection ForgottenDebugOutputInspection */
            error_log($image->getFilename() . ' → ' . $message, 3, $image->getPath() . '/_camerafilesrename_' . $this->runTimestamp . '.log');
            echo $message;

            return false;
        }

        if ($sourceRealPath === false || !rename($sourceRealPath, $targetPathname)) {
            echo "Error: Could not rename sidecar {$sidecar->getFilename()}." . PHP_EOL;

            return false;
        }

        $this->handledSidecars[strtolower($sourcePathname)] = true;
        $this->files[strtolower($targetFilename)] = $sidecar;
        echo $sourcePathname . " → $targetPathname" . PHP_EOL;

        return true;
    }



    /**
     * Emits optional machine-readable progress events for the Automator JXA wrapper.
     * Normal CLI runs do not emit these lines.
     *
     * @param array<string, array<string, string>> $groups
     */
    private function initializeProgress(array $groups): void {
        if (!$this->progressEnabled) {
            return;
        }

        foreach ($groups as $groupPathnames) {
            foreach ($groupPathnames as $pathname) {
                if (is_file($pathname) && !preg_match(self::LOG_FILENAME_PATTERN, basename($pathname))) {
                    $this->progressTotal++;
                }
            }
        }

        echo self::PROGRESS_PREFIX . "\tSTART\t" . $this->progressTotal . PHP_EOL;
        fflush(STDOUT);
    }



    private function reportProgress(SplFileInfo $file, bool $isRegularFile): void {
        if (!$this->progressEnabled || !$isRegularFile) {
            return;
        }

        $this->progressCompleted++;
        echo self::PROGRESS_PREFIX
            . "\tITEM\t"
            . $this->progressCompleted
            . "\t"
            . $this->progressTotal
            . "\t"
            . base64_encode($file->getFilename())
            . PHP_EOL;
        fflush(STDOUT);
    }



    /**
     * Converts one EXIF camera field into a safe uppercase alphanumeric
     * filename component.
     * Values that are empty or common EXIF placeholders are not recognizable
     * camera metadata and are omitted from the generated name.
     */
    private function normalizeCameraName(mixed $value): ?string {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $placeholder = strtolower(preg_replace('/\s+/', ' ', $value));
        if (in_array($placeholder, [
            '-',
            '?',
            'n/a',
            'na',
            'none',
            'null',
            'unknown',
            'undefined',
            'unrecognized',
            'not available',
        ], true)) {
            return null;
        }

        if (function_exists('iconv')) {
            $asciiValue = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($asciiValue !== false) {
                $value = $asciiValue;
            }
        }

        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
        if ($value === '' || !preg_match('/[A-Za-z0-9]/', $value)) {
            return null;
        }

        return $value;
    }



    /**
     * Normalizes a metadata identifier for use in a filename.
     * Identifiers use the same placeholder and alphanumeric rules as camera names,
     * but are kept separate semantically so their filename marker is explicit.
     */
    private function normalizeMetadataIdentifier(mixed $value): ?string {
        return $this->normalizeCameraName($value);
    }



    /**
     * Returns the optional camera suffix, including its leading underscore.
     * A partially populated EXIF record contributes whichever field is usable.
     */
    private function getCameraNameSuffix(array $exif): string {
        $make = $this->normalizeCameraName($exif['Make'] ?? null);
        $model = $this->normalizeCameraName($exif['Model'] ?? null);

        if ($make === 'LEICACAMERAAG' && $model !== null && str_contains($model, 'LEICA')) {
            return '_' . $model;
        }

        $parts = array_values(array_filter([$make, $model], static fn(?string $part): bool => $part !== null));

        return $parts === [] ? '' : '_' . implode('', $parts);
    }



    /**
     * Returns the best available EXIF sub-second identity token.
     * EXIF stores this as a decimal fraction string; values shorter than milliseconds
     * are right-padded so all generated millisecond tokens have the same meaning.
     */
    private function getSubsecondIdentity(array $exif, ?string $dateTimeField): ?string {
        $subsecondFields = match ($dateTimeField) {
            'DateTimeOriginal' => ['SubSecTimeOriginal', 'SubSecTime'],
            'DateTime' => ['SubSecTime', 'SubSecTimeOriginal'],
            default => ['SubSecTimeOriginal', 'SubSecTime'],
        };

        foreach ($subsecondFields as $subsecondField) {
            $value = $exif[$subsecondField] ?? null;
            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value === '' || !preg_match('/^[0-9]{1,9}$/D', $value)) {
                continue;
            }
            if (strlen($value) < 3) {
                $value = str_pad($value, 3, '0');
            }

            return 'S' . $value;
        }

        return null;
    }



    /**
     * Leica stores the camera-generated source filename in its private metadata.
     * PHP exposes the MakerNote manufacturer but not this private field, so inspect
     * the binary metadata for the conservative Leica source-name form.
     */
    private function getLeicaFrameIdentity(SplFileInfo $file, array $exif): ?string {
        $make = $this->normalizeCameraName($exif['Make'] ?? null);
        $model = $this->normalizeCameraName($exif['Model'] ?? null);
        if ($make !== 'LEICACAMERAAG'
            && ($model === null || !str_contains($model, 'LEICA'))
        ) {
            return null;
        }

        $source = @fopen($file->getPathname(), 'rb');
        if ($source === false) {
            return null;
        }

        $carry = '';
        $frame = null;
        while (!feof($source)) {
            $chunk = fread($source, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer = $carry . $chunk;
            if (preg_match(
                '/(?<![A-Z0-9])L(?<frame>[0-9]{5,})\.(?:DNG|JPE?G|RAF)(?![A-Z0-9])/i',
                $buffer,
                $matches
            ) === 1) {
                $frame = $matches['frame'];
                break;
            }
            $carry = substr($buffer, -64);
        }
        fclose($source);

        return $frame === null ? null : 'F' . $frame;
    }



    /**
     * Extracts a stable XMP document identifier when EXIF has no better identity.
     * OriginalDocumentID and instance IDs are preferred over Lightroom's settings UUID.
     */
    private function getXmpIdentity(array $exif, ?string $metadataPath = null): ?string {
        $xmp = '';
        foreach ($exif as $key => $value) {
            if (strcasecmp((string) $key, 'ExtensibleMetadataPlatform') !== 0
                && strcasecmp((string) $key, 'XMP') !== 0
            ) {
                continue;
            }
            if (is_string($value)) {
                $xmp .= $value;
            }
        }
        $patterns = [
            '/\bxmpMM:OriginalDocumentID\s*=\s*"([^"]+)"/i',
            '/\bxmpMM:DocumentID\s*=\s*"([^"]+)"/i',
            '/\bstRef:instanceID\s*=\s*"([^"]+)"/i',
            '/\bxmpMM:InstanceID\s*=\s*"([^"]+)"/i',
            '/\bcrs:UUID\s*=\s*"([^"]+)"/i',
        ];
        $extractIdentity = function (string $xmpData) use ($patterns): ?string {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $xmpData, $matches) !== 1) {
                    continue;
                }
                $identifier = $this->normalizeMetadataIdentifier($matches[1]);
                if ($identifier !== null) {
                    return 'X' . $identifier;
                }
            }

            return null;
        };

        $identity = $extractIdentity($xmp);
        if ($identity !== null) {
            return $identity;
        }

        if ($metadataPath === null) {
            return null;
        }

        $source = @fopen($metadataPath, 'rb');
        if ($source === false) {
            return null;
        }

        $bytesRead = 0;
        $scanLimit = 4 * 1024 * 1024;
        $carry = '';
        while ($bytesRead < $scanLimit && !feof($source)) {
            $chunkLength = min(65536, $scanLimit - $bytesRead);
            $chunk = fread($source, $chunkLength);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytesRead += strlen($chunk);
            $buffer = $carry . $chunk;
            $identity = $extractIdentity($buffer);
            if ($identity !== null) {
                fclose($source);

                return $identity;
            }
            $carry = substr($buffer, -16384);
        }
        fclose($source);

        return null;
    }



    /**
     * Chooses the strongest available image identity in priority order.
     * Content hashes are intentionally not used here.
     */
    private function getMetadataIdentity(
        SplFileInfo $file,
        array $exif,
        ?string $dateTimeField,
        ?string $metadataPath = null
    ): ?string {
        $subsecond = $this->getSubsecondIdentity($exif, $dateTimeField);
        if ($subsecond !== null) {
            return $subsecond;
        }

        $leicaFrame = $this->getLeicaFrameIdentity($file, $exif);
        if ($leicaFrame !== null) {
            return $leicaFrame;
        }

        $imageUniqueId = $this->normalizeMetadataIdentifier($exif['ImageUniqueID'] ?? null);
        if ($imageUniqueId !== null) {
            return 'U' . $imageUniqueId;
        }

        return $this->getXmpIdentity($exif, $metadataPath);
    }



    private function getOutputExtension(SplFileInfo $file): string {
        $extension = strtolower($file->getExtension());

        return $extension === 'jpg' ? 'jpeg' : $extension;
    }



    /**
     * Finds ImageMagick even when the script is launched with a minimal PATH,
     * as can happen from Finder, Automator, or a Quick Action on macOS.
     */
    private function findImageMagickCommand(): ?string {
        $candidates = [];
        $configuredPath = getenv('CAMERAFILESRENAME_MAGICK');
        if (is_string($configuredPath) && $configuredPath !== '') {
            $candidates[] = $configuredPath;
        }

        $path = getenv('PATH');
        if (is_string($path)) {
            foreach (explode(PATH_SEPARATOR, $path) as $directory) {
                if ($directory === '') {
                    $directory = getcwd() ?: '.';
                }
                $candidates[] = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'magick';
            }
        }

        // Homebrew's default locations on Apple Silicon and Intel macOS,
        // plus MacPorts and the conventional Unix location.
        $candidates = array_merge($candidates, [
            '/opt/homebrew/bin/magick',
            '/usr/local/bin/magick',
            '/opt/local/bin/magick',
            '/usr/bin/magick',
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }



    private function isICloudPlaceholder(SplFileInfo $file): bool {
        if (PHP_OS_FAMILY !== 'Darwin') {
            return false;
        }

        $pathname = $file->getRealPath();

        // Method 1: mdls kMDItemIsDownloaded (works on some macOS versions)
        $downloaded = @shell_exec('mdls -raw -name kMDItemIsDownloaded ' . escapeshellarg($pathname));
        if ($downloaded !== null && trim($downloaded) === '0') {
            return true;
        }

        // Method 2: For large files, check if actual disk usage is suspiciously small.
        // iCloud placeholders use sparse files where only a tiny stub is stored locally.
        $apparentSize = $file->getSize();
        if ($apparentSize > 100 * 1024) { // Only for files > 100KB
            $actualBlocks = @shell_exec('stat -f%b ' . escapeshellarg($pathname));
            if ($actualBlocks !== null) {
                $actualSize = (int)trim($actualBlocks) * 512;
                // If less than 8KB is actually on disk, it's almost certainly a placeholder
                if ($actualSize < 8192) {
                    return true;
                }
            }
        }

        return false;
    }



    /**
     * Extracts the embedded JPEG preview from a Fuji RAF file to $jpegPathname.
     * The RAF header stores the JPEG offset/length as big-endian uint32 at 0x54/0x58.
     * Returns false for non-RAF or truncated files so callers fall back gracefully.
     */
    private function extractRafEmbeddedJpeg(string $rafPathname, string $jpegPathname): bool {
        $source = @fopen($rafPathname, 'rb');
        if ($source === false) {
            return false;
        }
        $header = fread($source, 0x5C);
        if (strlen($header) < 0x5C || !str_starts_with($header, 'FUJIFILMCCD-RAW ')) {
            fclose($source);

            return false;
        }
        $directory = unpack('Noffset/Nlength', substr($header, 0x54, 8));
        $fileSize = (int) filesize($rafPathname);
        if ($directory['offset'] < 0x5C
            || $directory['length'] < 4
            || $directory['offset'] + $directory['length'] > $fileSize
        ) {
            fclose($source);

            return false;
        }
        fseek($source, $directory['offset']);
        if (fread($source, 2) !== "\xFF\xD8") { // JPEG SOI sanity check
            fclose($source);

            return false;
        }
        $target = @fopen($jpegPathname, 'wb');
        if ($target === false) {
            fclose($source);

            return false;
        }
        fseek($source, $directory['offset']);
        stream_copy_to_stream($source, $target, $directory['length']);
        fclose($target);
        fclose($source);

        return true;
    }



    private function processFile(SplFileInfo $file): bool {
        if ($this->isHandledSidecar($file)) {
            echo $file->getFilename() . " → Sidecar already handled with image." . PHP_EOL;

            return false;
        }

        if (!$file->isFile()) {
            echo "Not a file." . PHP_EOL;

            return false;
        }

        $newFilenamePrefix = preg_replace('/[0-9]/', '0', (new DateTime())->format(self::DATETIME_FORMAT));
        $originalFilename = $file->getFilename();
        echo $originalFilename . ' → ';
        if (str_starts_with($originalFilename, $newFilenamePrefix . '_')) {
            $originalFilename = substr($originalFilename, strlen($newFilenamePrefix . '_'));
        }

        if (!preg_match(self::ORIGINAL_FILENAME_PATTERN, $originalFilename)) {
            echo "Not matching original filename." . PHP_EOL;

            return false;
        }

        $renamedFilenameMatches = [];
        $filenameLooksRenamed = preg_match(
            self::RENAMED_FILENAME_PATTERN,
            $originalFilename,
            $renamedFilenameMatches
        ) === 1;
        $alreadyRenamed = $filenameLooksRenamed
            && (string) $file->getSize() === ($renamedFilenameMatches['size'] ?? '');
        $outputExtension = $this->getOutputExtension($file);
        $extensionNeedsNormalization = $filenameLooksRenamed
            && strtolower($renamedFilenameMatches['extension'] ?? '') !== $outputExtension;
        $encodedCameraName = strtoupper((string) ($renamedFilenameMatches['camera'] ?? ''));
        $encodedIdentity = strtoupper((string) ($renamedFilenameMatches['identity'] ?? ''));
        $needsMetadata = !$filenameLooksRenamed
            || !$alreadyRenamed
            || $extensionNeedsNormalization
            || $encodedCameraName === ''
            || $encodedIdentity === '';
        if ($alreadyRenamed
            && !$extensionNeedsNormalization
            && $encodedCameraName !== ''
            && $encodedIdentity !== ''
        ) {
            echo "Already renamed." . PHP_EOL;

            return $this->renameXmpSidecar($file, $file->getFilename());
        }

        $cameraNameSuffix = '';
        $identityToken = null;
        if ($needsMetadata && preg_match(self::VIDEO_FILENAME_PATTERN, $originalFilename)) {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($file->getMTime());
            $newFilenamePrefix = $dateTime->format(self::DATETIME_FORMAT);
        } elseif ($needsMetadata) {
            $tmpJpegPathname = null;
            if (preg_match('/\.heic$/i', $originalFilename)) {
                $tmpJpegPathname = $file->getPath() . "/_camerafilesrename_heictojpeg_" . preg_replace('/\.heic$/i', '.jpeg', $originalFilename);
                $magickCommand = $this->findImageMagickCommand();
                if ($magickCommand === null) {
                    echo "ImageMagick (magick) not found; leaving the HEIC file unchanged." . PHP_EOL;

                    return false;
                }
                @unlink($tmpJpegPathname);
                $conversionOutput = [];
                $conversionExitCode = 1;
                exec(
                    escapeshellarg($magickCommand) . ' '
                    . escapeshellarg($file->getRealPath()) . ' '
                    . escapeshellarg($tmpJpegPathname) . ' 2>&1',
                    $conversionOutput,
                    $conversionExitCode
                );
                if ($conversionExitCode !== 0 || !is_file($tmpJpegPathname)) {
                    @unlink($tmpJpegPathname);
                    echo "ImageMagick could not convert the HEIC file; leaving it unchanged." . PHP_EOL;

                    return false;
                }
            } elseif (preg_match('/\.raf$/i', $originalFilename)) {
                // PHP's EXIF reader cannot parse the RAF container; the EXIF lives in the
                // embedded JPEG preview, which is extracted to a temp file to read it.
                $tmpRafToJpgPathname = $file->getPath() . "/_camerafilesrename_raftojpeg_" . preg_replace('/\.raf$/i', '.jpeg', $originalFilename);
                if ($this->extractRafEmbeddedJpeg($file->getRealPath(), $tmpRafToJpgPathname)) {
                    $tmpJpegPathname = $tmpRafToJpgPathname;
                }
            }

            $exif = @exif_read_data($tmpJpegPathname ?? $file->getPathname()) ?: [];
            $cameraNameSuffix = $this->getCameraNameSuffix($exif);
            $dateTimeString = null;
            $dateTimeField = null;
            if (!empty($exif['DateTimeOriginal'])) {
                $dateTimeString = $exif['DateTimeOriginal'];
                $dateTimeField = 'DateTimeOriginal';
            } elseif (!empty($exif['DateTime'])) {
                $dateTimeString = $exif['DateTime'];
                $dateTimeField = 'DateTime';
            }
            $timestampString = $exif['FileDateTime'] ?? null;
            if ($dateTimeString) {
                $newFilenamePrefix = (new DateTime($dateTimeString))->format(self::DATETIME_FORMAT);
            } elseif ($timestampString) {
                $newFilenamePrefix = (new DateTime())->setTimestamp($timestampString)->format(self::DATETIME_FORMAT);
            } elseif ($this->isICloudPlaceholder($file)) {
                echo "iCloud file not fully downloaded, skipping." . PHP_EOL;

                if ($tmpJpegPathname !== null) {
                    unlink($tmpJpegPathname);
                }

                return false;
            }

            $identityToken = $this->getMetadataIdentity(
                $file,
                $exif,
                $dateTimeField,
                $file->getPathname()
            );

            if ($tmpJpegPathname !== null) {
                unlink($tmpJpegPathname);
            }
        }

        if ($alreadyRenamed && !$extensionNeedsNormalization) {
            $cameraIsCurrent = $cameraNameSuffix === ''
                || $encodedCameraName === ltrim($cameraNameSuffix, '_');
            $identityIsCurrent = $identityToken === null
                || ($encodedIdentity !== '' && $encodedIdentity === $identityToken);
            if ($cameraIsCurrent && $identityIsCurrent) {
                echo "Already renamed." . PHP_EOL;

                return $this->renameXmpSidecar($file, $file->getFilename());
            }
        }

        $identitySuffix = $identityToken === null ? '' : '_' . $identityToken;
        $baseName = $newFilenamePrefix . $identitySuffix . '_' . $file->getSize() . $cameraNameSuffix;
        $newFilename = $baseName . '.' . $outputExtension;
        $newFilePath = $file->getPath() . '/' . $newFilename;
        $targetExistsInIndex = $this->lowerFilenameExist($newFilename);
        $targetExistsOnDisk = file_exists($newFilePath);

        if ($targetExistsInIndex || $targetExistsOnDisk) {
            $sourceRealPath = $file->getRealPath();
            $targetRealPath = realpath($newFilePath);
            if ($targetRealPath !== false && $targetRealPath === $sourceRealPath) {
                // The file is already named exactly what we would rename it to (e.g. a zero-date
                // file on re-run). Keep the existing behavior: log and skip, do not add a suffix.
                $message = "Error: New filename $newFilename for {$file->getFilename()} already exists." . PHP_EOL;
                /** @noinspection ForgottenDebugOutputInspection */
                error_log($originalFilename . ' → ' . $message, 3, $file->getPath() . '/_camerafilesrename_' . $this->runTimestamp . '.log');
                echo $message;

                return false;
            }

            $suffix = 0;
            do {
                $newFilename = sprintf('%s_DUP%03d.%s', $baseName, $suffix, $outputExtension);
                $newFilePath = $file->getPath() . '/' . $newFilename;
                $suffix++;
            } while ($this->lowerFilenameExist($newFilename) || file_exists($newFilePath));
        }

        rename($file->getRealPath(), $newFilePath);
        $this->files[strtolower($newFilename)] = $file;
        echo $file->getRealPath() . " → $newFilePath" . PHP_EOL;

        $this->renameXmpSidecar($file, $newFilename);

        return true;
    }

})();
