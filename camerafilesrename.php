<?php


(new class() {

    private const RENAMED_FILENAME_PATTERN = '/^[0-9]{8}_[0-9]{6}_[0-9]+(\([0-9]{3}\))?\.(dng|jpg|jpeg|heic|raf|mov|mp4)$/i';

    private const ORIGINAL_FILENAME_PATTERN = '/^[A-Z0-9_\-]+(\([0-9]{3}\))?( [0-9]+)?\.(dng|jpg|jpeg|raf|heic|mov|mp4)$/i';

    private const VIDEO_FILENAME_PATTERN = '/\.(mov|mp4)$/i';

    private const LOG_FILENAME_PATTERN = '/^_camerafilesrename_[0-9]{10}\.log$/i';

    private const DATETIME_FORMAT = 'Ymd_His';

    /** @var SplFileInfo[] */
    private array $files = [];

    private int $runTimestamp;

    private int $renamedCount = 0;

    private int $skippedCount = 0;

    private bool $debugEnabled = false;



    public function __invoke(): void {
        global $argv;
        $arguments = array_slice($argv, 1);
        $this->debugEnabled = in_array('--debug', $arguments, true);
        if ($this->debugEnabled) {
            $arguments = array_values(array_diff($arguments, ['--debug']));
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

            foreach ($this->files as $file) {
                if ($this->processFile($file)) {
                    $this->renamedCount++;
                } else {
                    $this->skippedCount++;
                }
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



    private function processFile(SplFileInfo $file): bool {
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

        if (preg_match(self::RENAMED_FILENAME_PATTERN, $originalFilename)) {
            echo "Already renamed." . PHP_EOL;

            return false;
        }

        if (preg_match(self::VIDEO_FILENAME_PATTERN, $originalFilename)) {
            $dateTime = new DateTime();
            $dateTime->setTimestamp($file->getMTime());
            $newFilenamePrefix = $dateTime->format(self::DATETIME_FORMAT);
        } else {
            if (preg_match('/\.heic$/i', $originalFilename)) {
                $tmpHeicToJpgPathname = $file->getPath() . "/_camerafilesrename_heictojpeg_" . preg_replace('/\.heic$/i', '.jpeg', $originalFilename);
                exec('magick convert ' . escapeshellarg($file->getRealPath()) . ' ' . escapeshellarg($tmpHeicToJpgPathname));
                if (!file_exists($tmpHeicToJpgPathname)) {
                    unset($tmpHeicToJpgPathname);
                }
            }

            $exif = @exif_read_data($tmpHeicToJpgPathname ?? $file->getPathname()) ?: [];
            //var_dump($exif);
            $dateTimeString = $exif['DateTimeOriginal'] ?? $exif['DateTime'] ?? null;
            $timestampString = $exif['FileDateTime'] ?? null;
            if ($dateTimeString) {
                $newFilenamePrefix = (new DateTime($dateTimeString))->format(self::DATETIME_FORMAT);
            } elseif ($timestampString) {
                $newFilenamePrefix = (new DateTime())->setTimestamp($timestampString)->format(self::DATETIME_FORMAT);
            } elseif ($this->isICloudPlaceholder($file)) {
                echo "iCloud file not fully downloaded, skipping." . PHP_EOL;

                if (isset($tmpHeicToJpgPathname)) {
                    unlink($tmpHeicToJpgPathname);
                }

                return false;
            }

            if (isset($tmpHeicToJpgPathname)) {
                unlink($tmpHeicToJpgPathname);
            }
        }
        $newFilename = $newFilenamePrefix . '_' . $file->getSize() . '.' . strtolower($file->getExtension());
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

            $baseName = $newFilenamePrefix . '_' . $file->getSize();
            $extension = strtolower($file->getExtension());
            $suffix = 1;
            do {
                $newFilename = sprintf('%s(%03d).%s', $baseName, $suffix, $extension);
                $newFilePath = $file->getPath() . '/' . $newFilename;
                $suffix++;
            } while ($this->lowerFilenameExist($newFilename) || file_exists($newFilePath));
        }

        rename($file->getRealPath(), $newFilePath);
        $this->files[strtolower($newFilename)] = $file;
        echo $file->getRealPath() . " → $newFilePath" . PHP_EOL;

        return true;
    }

})();