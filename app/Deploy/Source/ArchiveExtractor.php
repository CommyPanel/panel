<?php

namespace App\Deploy\Source;

use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Unpacks an uploaded .zip or .tar.gz into a directory, treating the archive
 * as hostile: every name is checked before anything is written, links and
 * special files are refused, and the unpacked size and file count are capped.
 */
class ArchiveExtractor
{
    private const TAR_TIMEOUT_SECONDS = 180;

    /**
     * @return int the number of files unpacked
     *
     * @throws InvalidArchive
     */
    public function extract(string $archive, string $destination): int
    {
        File::ensureDirectoryExists($destination, 0750);

        match ($this->type($archive)) {
            'zip' => $this->unzip($archive, $destination),
            'tar' => $this->untar($archive, $destination),
        };

        return $this->finalize($destination);
    }

    private function maxBytes(): int
    {
        return (int) config('commypanel.uploads.max_unpacked_mb') * 1024 * 1024;
    }

    private function maxFiles(): int
    {
        return (int) config('commypanel.uploads.max_files');
    }

    private function tooLarge(): InvalidArchive
    {
        return new InvalidArchive(sprintf(
            'The project is too large once unpacked (the limit is %d MB and %d files). Leave out node_modules, build output and other generated files.',
            config('commypanel.uploads.max_unpacked_mb'),
            $this->maxFiles(),
        ));
    }

    /**
     * Decided by the file's first bytes, never by its name.
     *
     * @return 'zip'|'tar'
     */
    private function type(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return 'zip';
        }

        if (str_starts_with($head, "\x1f\x8b")) {
            return 'tar';
        }

        throw new InvalidArchive(__('That file is not a .zip, .tar.gz or .tgz archive.'));
    }

    /**
     * A safe relative path for an archive entry, or null for junk that is
     * silently left out (macOS metadata, .git folders).
     *
     * @throws InvalidArchive
     */
    private function safePath(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);

        if ($name === '' || $name === '.' || $name === './') {
            return null;
        }

        if (str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('#^[A-Za-z]:#', $name) === 1) {
            throw new InvalidArchive(__('The archive contains an unsafe path (:printable_name). Create it again from the project folder.', ['printable_name' => $this->printable($name)]));
        }

        $segments = array_values(array_filter(explode('/', $name), fn (string $segment) => $segment !== '' && $segment !== '.'));

        if (in_array('..', $segments, true)) {
            throw new InvalidArchive(__('The archive contains an unsafe path (:printable_name). Create it again from the project folder.', ['printable_name' => $this->printable($name)]));
        }

        if ($segments === [] || in_array('__MACOSX', $segments, true) || in_array('.git', $segments, true) || end($segments) === '.DS_Store') {
            return null;
        }

        return implode('/', $segments);
    }

    private function printable(string $name): string
    {
        return mb_substr(preg_replace('/[^\x20-\x7e]/', '?', $name) ?? '', 0, 80);
    }

    private function linkError(string $name): InvalidArchive
    {
        return new InvalidArchive("The archive contains a link or special file ({$this->printable($name)}). Remove symbolic links and upload again.");
    }

    private function unzip(string $archive, string $destination): void
    {
        $zip = new ZipArchive;

        if ($zip->open($archive, ZipArchive::RDONLY) !== true) {
            throw new InvalidArchive(__('The ZIP file could not be opened. It may be damaged.'));
        }

        try {
            $files = 0;
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false || ($path = $this->safePath($stat['name'])) === null) {
                    continue;
                }

                $zip->getExternalAttributesIndex($i, $system, $attributes);
                $mode = $system === ZipArchive::OPSYS_UNIX ? ($attributes >> 16) & 0xFFFF : 0;

                if (($mode & 0170000) === 0120000) {
                    throw $this->linkError($stat['name']);
                }

                if (str_ends_with($stat['name'], '/')) {
                    File::ensureDirectoryExists($destination.'/'.$path, 0750);

                    continue;
                }

                $files++;
                $total += (int) $stat['size'];

                if ($files > $this->maxFiles() || $total > $this->maxBytes()) {
                    throw $this->tooLarge();
                }

                $this->writeZipEntry($zip, $stat['name'], $destination.'/'.$path, $total, ($mode & 0100) !== 0);
            }
        } finally {
            $zip->close();
        }
    }

    private function writeZipEntry(ZipArchive $zip, string $name, string $target, int $declaredTotal, bool $executable): void
    {
        File::ensureDirectoryExists(dirname($target), 0750);

        $in = $zip->getStream($name);
        $out = fopen($target, 'wb');

        if ($in === false || $out === false) {
            throw new InvalidArchive(__('The ZIP file could not be read. It may be damaged.'));
        }

        // The declared size can lie in a hostile archive, so count what is really written.
        $written = 0;

        while (! feof($in)) {
            $chunk = fread($in, 65536);

            if ($chunk === false) {
                break;
            }

            $written += strlen($chunk);

            if ($written > $this->maxBytes()) {
                fclose($in);
                fclose($out);

                throw $this->tooLarge();
            }

            fwrite($out, $chunk);
        }

        fclose($in);
        fclose($out);
        chmod($target, $executable ? 0755 : 0644);
    }

    private function untar(string $archive, string $destination): void
    {
        $limit = (string) ($this->maxBytes() + 1);
        $stream = 'gzip -dc -- "$1" | head -c "$2" | ';

        $names = $this->tar($stream.'tar -tf -', [$archive, $limit]);
        $verbose = $this->tar($stream.'tar -tvf -', [$archive, $limit]);

        $names = $names === '' ? [] : explode("\n", rtrim($names, "\n"));
        $kinds = $verbose === '' ? [] : array_map(fn (string $line) => substr($line, 0, 1), explode("\n", rtrim($verbose, "\n")));

        // A name containing a line break would shift the two lists apart; refuse it.
        if (count($names) !== count($kinds)) {
            throw new InvalidArchive(__('The archive contains unusual file names. Create it again from the project folder.'));
        }

        $files = 0;

        foreach ($names as $index => $name) {
            if (! in_array($kinds[$index], ['-', 'd'], true)) {
                throw $this->linkError($name);
            }

            if ($this->safePath($name) !== null && $kinds[$index] === '-' && ++$files > $this->maxFiles()) {
                throw $this->tooLarge();
            }
        }

        $this->tar($stream.'tar -xf - -C "$3" --no-same-owner --no-same-permissions', [$archive, $limit, $destination]);
    }

    /**
     * Runs a fixed shell pipeline whose variable parts are passed as arguments
     * ($1, $2, ...), never spliced into the command text.
     *
     * @param  list<string>  $arguments
     */
    private function tar(string $script, array $arguments): string
    {
        $result = Process::timeout(self::TAR_TIMEOUT_SECONDS)->run(['sh', '-c', $script, 'sh', ...$arguments]);

        if ($result->failed()) {
            throw str_contains(strtolower($result->errorOutput()), 'truncated') || str_contains(strtolower($result->errorOutput()), 'unexpected eof')
                ? $this->tooLarge()
                : new InvalidArchive('The archive could not be read. Is it a valid .tar.gz file?');
        }

        return $result->output();
    }

    /**
     * Final clean-up and safety net, whichever format was unpacked: drop
     * junk, remove any link that slipped through, enforce the limits, and
     * unwrap a single top-level folder.
     */
    private function finalize(string $destination): int
    {
        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destination, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                File::delete($entry->getPathname());

                continue;
            }

            $relative = substr($entry->getPathname(), strlen(rtrim($destination, '/')) + 1);

            if ($this->safePath($relative) === null) {
                $entry->isDir() ? File::deleteDirectory($entry->getPathname()) : File::delete($entry->getPathname());

                continue;
            }

            if ($entry->isFile()) {
                $files++;
                $bytes += $entry->getSize();

                if ($files > $this->maxFiles() || $bytes > $this->maxBytes()) {
                    throw $this->tooLarge();
                }
            }
        }

        $this->unwrapSingleFolder($destination);

        if ($files === 0) {
            throw new InvalidArchive(__('The archive is empty.'));
        }

        return $files;
    }

    private function unwrapSingleFolder(string $destination): void
    {
        $entries = array_values(array_diff(scandir($destination) ?: [], ['.', '..']));

        if (count($entries) !== 1 || ! is_dir($destination.'/'.$entries[0])) {
            return;
        }

        $inner = $destination.'/'.$entries[0];

        foreach (array_diff(scandir($inner) ?: [], ['.', '..']) as $child) {
            rename($inner.'/'.$child, $destination.'/'.$child);
        }

        rmdir($inner);
    }
}
