<?php

namespace Lukraak\MollieIncasso;

/** Plugin-owned key outside the database; direct PHP requests always exit. */
final class KeyFile
{
    public function __construct(private readonly string $directory = WP_CONTENT_DIR . '/.mollie-incasso-keys')
    {
    }

    private function path(): string
    {
        $site = function_exists('get_current_blog_id') ? get_current_blog_id() : 1;
        return $this->directory . '/site-' . substr(hash('sha256', ABSPATH . '|' . $site), 0, 24) . '.php';
    }

    public function key(bool $create = false): string
    {
        if (is_link($this->directory) || is_link($this->path())) {
            throw new Failure('key_file_unavailable');
        }
        if (!is_dir($this->directory)) {
            if (!$create || !@mkdir($this->directory, 0700, false) && !is_dir($this->directory)) {
                throw new Failure('key_file_unavailable');
            }
        }
        if (DIRECTORY_SEPARATOR === '/' && ((int) @fileperms($this->directory) & 0077) !== 0) {
            if (!@chmod($this->directory, 0700)) {
                throw new Failure('key_file_unavailable');
            }
            clearstatcache(true, $this->directory);
            if (((int) @fileperms($this->directory) & 0077) !== 0) throw new Failure('key_file_unavailable');
        }
        $file = @fopen($this->path(), 'rb');
        if ($file !== false) {
            try {
                if (!flock($file, LOCK_SH)) throw new Failure('key_file_unavailable');
                $content = stream_get_contents($file, 512);
                if (!is_string($content) || !preg_match('/^<\?php\nhttp_response_code\(404\);\nexit;\n\/\* MI_KEY_V1:([A-Za-z0-9+\/=]{44}) \*\/\n$/D', $content, $match)) {
                    throw new Failure('key_file_unavailable');
                }
                $key = base64_decode($match[1], true);
                if (!is_string($key) || strlen($key) !== 32) throw new Failure('key_file_unavailable');
                return $key;
            } finally { fclose($file); }
        }
        if (!$create || file_exists($this->path())) throw new Failure('key_file_unavailable');
        // Exclusive creation never replaces a key that another request just created.
        $file = @fopen($this->path(), 'x+b');
        if ($file === false) {
            clearstatcache(true, $this->path());
            if (is_file($this->path()) && !is_link($this->path())) return $this->key(false);
            throw new Failure('key_file_unavailable');
        }
        try {
            if (!flock($file, LOCK_EX)) throw new Failure('key_file_unavailable');
            if (DIRECTORY_SEPARATOR === '/' && !@chmod($this->path(), 0600)) throw new Failure('key_file_unavailable');
            $key = random_bytes(32);
            $content = "<?php\nhttp_response_code(404);\nexit;\n/* MI_KEY_V1:" . base64_encode($key) . " */\n";
            if (fwrite($file, $content) !== strlen($content) || !fflush($file)) throw new Failure('key_file_unavailable');
            if (function_exists('fsync') && !fsync($file)) throw new Failure('key_file_unavailable');
            return $key;
        } finally { fclose($file); }
    }
}
