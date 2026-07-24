<?php


(new class() {

    private const RENAMED_FILENAME_PATTERN = '/^[0-9]{14}_[A-Z0-9_\-]+( [0-9]+)?\.(dng|jpg|jpeg|heic|raf|mov|mp4)$/i';

    private const ORIGINAL_FILENAME_PATTERN = '/^[A-Z0-9_\-]+( [0-9]+)?\.(dng|jpg|jpeg|raf|heic|mov|mp4)$/i';

    private const VIDEO_FILENAME_PATTERN = '/\.(mov|mp4)$/i';

    private const LOG_FILENAME_PATTERN = '/^_camerabatch_[0-9]{10}\.log$/i';

    private const DATETIME_FORMAT = 'YmdHis';

    /** @var SplFileInfo[] */
    private array $files;

    private string $errorLogFilePathname;



    public function __invoke(): void {
        global $argv;
        $dirPath = $argv[1] ?? null;
        if (!$dirPath || !is_dir($dirPath)) {
            exit("Path $dirPath is not director.");
        }

        $this->errorLogFilePathname = $dirPath . '/_camerabatch_' . time() . '.log';
        $this->files = iterator_to_array(
            new FilesystemIterator(
                $dirPath,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::KEY_AS_FILENAME
            )
        );

        foreach ($this->files as $filename => $file) {
            if (preg_match(self::LOG_FILENAME_PATTERN, $file->getFilename())) {
                unset($this->files[$filename]);
                continue;
            }
            if ($this->lowerFilenameExist($file->getFilename())) {
                exit("Error: Lower filename for $filename already exist.");
            }
            $this->files[strtolower($filename)] = $file;
            unset($this->files[$filename]);
        }

        foreach ($this->files as $file) {
            $this->processFile($file);
        }

        ksort($this->files);
    }



    private function lowerFilenameExist(string $filename): bool {
        return array_key_exists(strtolower($filename), $this->files);
    }



    private function processFile(SplFileInfo $file): bool {
        if (!$file->isFile()) {
            echo "Not a file." . PHP_EOL;

            return false;
        }

        $newFilenamePrefix = str_repeat('0', strlen((new DateTime())->format(self::DATETIME_FORMAT)));
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
                $tmpHeicToJpgPathname = $file->getPath() . "/_camerabatch_heictojpeg_" . preg_replace('/\.heic$/i', '.jpeg', $originalFilename);
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
            }

            if (isset($tmpHeicToJpgPathname)) {
                unlink($tmpHeicToJpgPathname);
            }
        }
        $newFilename = $newFilenamePrefix . '_' . $originalFilename;
        if ($this->lowerFilenameExist($newFilename)) {
            $message = "Error: New filename $newFilename for {$file->getFilename()} already exists." . PHP_EOL;
            /** @noinspection ForgottenDebugOutputInspection */
            error_log($originalFilename . ' → ' . $message, 3, $this->errorLogFilePathname);
            echo $message;

            return false;
        }

        $newFilePath = $file->getPath() . '/' . $newFilename;
        rename($file->getRealPath(), $newFilePath);
        echo $file->getRealPath() . " → $newFilePath" . PHP_EOL;

        return true;
    }

})();