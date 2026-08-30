<?php

declare(strict_types=1);

namespace QRIVO\Infrastructure\Config;

/**
 * Configuration loader.
 *
 * Reads PHP config files from config/ and environment variables.
 * Provides typed accessors.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $basePath)
    {
        $this->load('app');
        $this->load('database');
        $this->load('logging');
        $this->load('auth');
    }

    /**
     * Load a config file by name (e.g. 'app' → config/app.php).
     */
    private function load(string $name): void
    {
        $file = $this->basePath . '/config/' . $name . '.php';

        if (file_exists($file)) {
            $values = require $file;
            if (is_array($values)) {
                $this->data[$name] = $values;
            }
        }
    }

    /**
     * Get a config value using dot notation.
     * Example: get('database.host')
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $parts   = explode('.', $key);
        $current = $this->data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }

    public function getString(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->get($key, $default);

        if (is_bool($val)) {
            return $val;
        }

        return in_array(strtolower((string) $val), ['true', '1', 'yes'], true);
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }
}
