<?php

namespace Dynamic\SilverStripe\UserInvitations\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the composer.json "autoload.psr-4" mapping against the namespaces the classes
 * in this module actually declare.
 *
 * The module used to map "Dynamic\UserInvitations\" and "Dynamic\UserInvitations\Test\"
 * while every class declared "Dynamic\SilverStripe\UserInvitations\*". Neither mapping
 * matched what it pointed at, so nothing in the module was resolvable through Composer's
 * autoloader - only through SilverStripe's class manifest, which scans directories and
 * never reads composer.json. This test resolves each class through a fresh
 * {@see ClassLoader} built from composer.json alone, so a mapping that drifts away from
 * the declared namespaces fails here instead of in a consuming project.
 */
class AutoloadPsr4Test extends TestCase
{
    /**
     * Directories whose classes the "autoload" (not "autoload-dev") block has to cover.
     *
     * @var string[]
     */
    private const DIRECTORIES = ['src', 'tests/php'];

    /**
     * Every class in the module resolves through the composer.json PSR-4 mapping.
     */
    public function testComposerPsr4MappingResolvesEveryClassInModule(): void
    {
        $root = dirname(__DIR__, 2);
        $mapping = $this->psr4Mapping($root);
        $this->assertNotEmpty($mapping, 'composer.json declares no autoload.psr-4 mapping.');

        $loader = new ClassLoader();
        foreach ($mapping as $prefix => $dir) {
            $loader->addPsr4($prefix, rtrim($root . '/' . $dir, '/'), false);
        }

        $checked = 0;
        foreach ($this->phpFiles($root) as $path) {
            $class = $this->fqcnOf($path);
            if ($class === null) {
                continue;
            }
            $checked++;
            $resolved = $loader->findFile($class);
            if ($resolved === false) {
                $this->fail(sprintf(
                    '%s (in %s) is not PSR-4 autoloadable from the composer.json mapping (%s).',
                    $class,
                    $this->relative($root, $path),
                    $this->formatMapping($mapping)
                ));
            }
            $this->assertSame(
                $this->relative($root, (string)$resolved),
                $this->relative($root, $path),
                sprintf('%s resolves to the wrong file.', $class)
            );
        }

        $this->assertGreaterThan(0, $checked, 'No PHP classes were checked; the test is vacuous.');
    }

    /**
     * Every mapped prefix is a namespace at least one class actually lives under.
     *
     * A prefix no class lives under is dead weight, and it is exactly what let
     * "Dynamic\UserInvitations\" sit in composer.json long after the code moved.
     */
    public function testEveryMappedPrefixIsUsedByAtLeastOneClass(): void
    {
        $root = dirname(__DIR__, 2);
        $mapping = $this->psr4Mapping($root);
        $classes = [];

        foreach ($this->phpFiles($root) as $path) {
            $class = $this->fqcnOf($path);
            if ($class !== null) {
                $classes[] = $class;
            }
        }

        foreach (array_keys($mapping) as $prefix) {
            $matching = array_values(array_filter(
                $classes,
                fn (string $class): bool => str_starts_with($class, $prefix)
            ));
            $this->assertNotEmpty(
                $matching,
                sprintf(
                    'composer.json maps "%s", but no class in the module is under that namespace.',
                    $prefix
                )
            );
        }
    }

    /**
     * @return array<string, string> PSR-4 prefix => directory, relative to the module root.
     */
    private function psr4Mapping(string $root): array
    {
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true);
        $this->assertIsArray($composer, 'composer.json is not readable as JSON.');

        return $composer['autoload']['psr-4'] ?? [];
    }

    /**
     * Paths of the PHP files the PSR-4 mapping is expected to cover.
     *
     * @return string[]
     */
    private function phpFiles(string $root): array
    {
        $paths = [];
        foreach (self::DIRECTORIES as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                    $paths[] = $item->getPathname();
                }
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * The fully qualified name of the type a file declares, or null if it declares none.
     *
     * Handles the PHP 8.2+ `readonly class` modifier as well as `final` and `abstract`.
     */
    private function fqcnOf(string $path): ?string
    {
        $source = (string)file_get_contents($path);

        if (!preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $source, $namespace)) {
            return null;
        }
        if (!preg_match(
            '/^\s*(?:final\s+|abstract\s+)?(?:readonly\s+)?(?:class|interface|trait|enum)\s+(\w+)/m',
            $source,
            $declaration
        )) {
            return null;
        }

        return trim($namespace[1], '\\') . '\\' . $declaration[1];
    }

    private function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * @param array<string, string> $mapping
     */
    private function formatMapping(array $mapping): string
    {
        $parts = [];
        foreach ($mapping as $prefix => $dir) {
            $parts[] = sprintf('%s => %s', $prefix, $dir);
        }

        return implode(', ', $parts);
    }
}
