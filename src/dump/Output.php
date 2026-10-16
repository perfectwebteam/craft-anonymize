<?php

namespace perfectwebteam\anonymize\dump;

use DeflateContext;
use RuntimeException;

/**
 * Where a dump goes: stdout or a file, gzipped as it streams if asked.
 *
 * A file is written as `{path}.part`, readable by its owner only, and renamed
 * to `{path}` by finish(): a dump that stopped halfway never sits under the
 * name of a finished one. abort() removes it.
 */
final class Output
{
    /** Uncompressed bytes written. */
    public int $bytes = 0;

    private ?DeflateContext $deflate = null;

    /**
     * @param resource $stream
     */
    private function __construct(
        private $stream,
        bool $gzip,
        private readonly ?string $path = null,
    ) {
        if ($gzip) {
            if (!function_exists('deflate_init')) {
                throw new RuntimeException('--gzip vraagt de zlib-extensie van PHP, en die ontbreekt');
            }
            $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        }
    }

    public static function stdout(bool $gzip): self
    {
        return new self(STDOUT, $gzip);
    }

    public static function file(string $path, bool $gzip): self
    {
        if (file_exists($path)) {
            throw new RuntimeException("{$path} bestaat al; kies een andere naam of ruim hem eerst op");
        }
        $part = "{$path}.part";
        $umask = umask(0077);
        try {
            $stream = @fopen($part, 'x');
        } finally {
            umask($umask);
        }
        if ($stream === false) {
            throw new RuntimeException("Kan {$part} niet aanmaken (bestaat hij al, of mag het niet?)");
        }

        return new self($stream, $gzip, $path);
    }

    public function write(string $data): void
    {
        $this->bytes += strlen($data);
        $this->writeAll($this->deflate ? deflate_add($this->deflate, $data, ZLIB_NO_FLUSH) : $data);
    }

    /** Ends the stream; a file gets its real name only now. */
    public function finish(): void
    {
        if ($this->deflate) {
            $this->writeAll(deflate_add($this->deflate, '', ZLIB_FINISH));
            $this->deflate = null;
        }
        if (!fflush($this->stream)) {
            throw new RuntimeException('Het laatste stuk van de dump kwam niet weg');
        }
        if ($this->path !== null) {
            fclose($this->stream);
            if (!rename("{$this->path}.part", $this->path)) {
                throw new RuntimeException("Kan {$this->path}.part niet hernoemen naar {$this->path}");
            }
        }
    }

    /** After a failure: a file goes, so nothing passes for a finished dump. */
    public function abort(): void
    {
        if ($this->path !== null) {
            if (is_resource($this->stream)) {
                fclose($this->stream);
            }
            @unlink("{$this->path}.part");
        }
    }

    /** A full disk writes less than asked rather than failing: keep going, or stop. */
    private function writeAll(string $data): void
    {
        $length = strlen($data);
        for ($done = 0; $done < $length; $done += $written) {
            $written = fwrite($this->stream, $done === 0 ? $data : substr($data, $done));
            if (!$written) {
                throw new RuntimeException('Schrijven naar de uitvoer mislukte (schijf vol, of de lezer is gestopt)');
            }
        }
    }
}
