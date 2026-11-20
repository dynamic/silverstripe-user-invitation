<?php

namespace Dynamic\SilverStripe\UserInvitations\Tests;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regression test for the composer.json autoload mappings drifting away from the
 * namespaces this module's classes actually declare.
 *
 * SilverStripe's own class manifest scans src/ and tests/ regardless of what composer.json
 * says, so the module boots and its suite runs even when the psr-4 mapping matches nothing.
 * These checks deliberately bypass that manifest: they build a fresh Composer psr-4 loader
 * from composer.json alone, so the mapping - not the manifest - has to resolve every class.
 *
 * @see https://github.com/dynamic/silverstripe-user-invitation/issues/45
 */
class ComposerAutoloadTest extends TestCase
{
    /**
     * Absolute path to the module root (this file lives in tests/php/).
     */
    private const MODULE_ROOT = __DIR__ . '/../..';

    /**
     * The source directories composer.json maps, keyed by path, each with the root
     * namespace every file below it declares.
     *
     * @var array<string, string>
     */
    private const SOURCE_DIRS = [
        'src' => 'Dynamic\\SilverStripe\\UserInvitations',
        'tests/php' => 'Dynamic\\SilverStripe\\UserInvitations\\Tests',
    ];

    /**
     * Every namespace declared under a mapped directory must sit below the root namespace
     * that composer.json maps that directory to.
     */
    public function testPsr4MappingsMatchTheNamespacesInUse(): void
    {
        $psr4 = $this->composerPsr4Mappings();
        $this->assertNotEmpty($psr4, 'composer.json declares no autoload.psr-4 mappings.');

        foreach (self::SOURCE_DIRS as $dir => $rootNamespace) {
            $declared = $this->declaredNamespaces($dir);
            $this->assertNotEmpty($declared, sprintf('Found no namespaces under "%s/".', $dir));

            // The mapping the issue asks for: "<root namespace>\\" => "<dir>/".
            $this->assertArrayHasKey(
                $rootNamespace . '\\',
                $psr4,
                sprintf('composer.json does not map "%s\\" to "%s/".', $rootNamespace, $dir)
            );
            $this->assertSame(
                $dir . '/',
                $psr4[$rootNamespace . '\\'],
                sprintf('composer.json maps "%s\\" somewhere other than "%s/".', $rootNamespace, $dir)
            );

            foreach ($declared as $namespace) {
                $this->assertTrue(
                    $namespace === $rootNamespace
                        || str_starts_with($namespace, $rootNamespace . '\\'),
                    sprintf('Namespace "%s" under "%s/" is not covered by "%s\\".', $namespace, $dir, $rootNamespace)
                );
            }
        }
    }

    /**
     * A psr-4 loader built only from composer.json must resolve every class in the module to
     * the file that declares it - which is what a consuming site, or any tool that resolves
     * classes through Composer rather than SilverStripe's manifest, relies on.
     */
    public function testComposerAutoloaderResolvesEveryClassInTheModule(): void
    {
        $loader = new ClassLoader();
        foreach ($this->composerPsr4Mappings() as $namespace => $dir) {
            $loader->addPsr4($namespace, self::MODULE_ROOT . '/' . trim($dir, '/'));
        }

        $checked = 0;
        foreach (array_keys(self::SOURCE_DIRS) as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $class = $this->fullyQualifiedClass($file);
                $found = $loader->findFile($class);

                $this->assertIsString(
                    $found,
                    sprintf('Composer could not autoload "%s" from the psr-4 mappings in composer.json.', $class)
                );
                $this->assertSame(
                    realpath($file),
                    realpath((string)$found),
                    sprintf('Composer resolved "%s" to the wrong file.', $class)
                );
                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No class file was checked, so this test proves nothing.');
    }

    /**
     * Every prefix in the mapping must be a namespace at least one class in the module actually
     * lives under. A prefix nothing lives under is dead weight, and it is exactly the drift this
     * issue is about: "Dynamic\\UserInvitations\\" sat in composer.json long after the code moved.
     */
    public function testEveryMappedPrefixIsUsedByAtLeastOneClass(): void
    {
        $classes = [];
        foreach (array_keys(self::SOURCE_DIRS) as $dir) {
            foreach ($this->phpFiles($dir) as $file) {
                $classes[] = $this->fullyQualifiedClass($file);
            }
        }
        $this->assertNotEmpty($classes, 'No class file was found, so this test proves nothing.');

        foreach (array_keys($this->composerPsr4Mappings()) as $prefix) {
            $prefix = (string)$prefix;
            $root = rtrim($prefix, '\\');
            $this->assertTrue(
                $this->anyClassLivesUnder($root, $classes),
                sprintf('composer.json maps "%s", but no class in the module lives under it.', $prefix)
            );
        }
    }

    /**
     * @param string $root Namespace without its trailing separator.
     * @param list<string> $classes Fully qualified class names to test against.
     * @return bool True when one of $classes is $root itself or sits below it.
     */
    private function anyClassLivesUnder(string $root, array $classes): bool
    {
        foreach ($classes as $class) {
            if ($class === $root || str_starts_with($class, $root . '\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string> The psr-4 map from composer.json, namespace prefix => dir.
     */
    private function composerPsr4Mappings(): array
    {
        $manifest = json_decode((string)file_get_contents(self::MODULE_ROOT . '/composer.json'), true);
        $this->assertIsArray($manifest, 'composer.json is not readable as JSON.');
        $psr4 = $manifest['autoload']['psr-4'] ?? [];
        $this->assertIsArray($psr4, 'composer.json autoload.psr-4 is not an object.');

        foreach ($psr4 as $namespace => $dir) {
            $this->assertIsString(
                $dir,
                sprintf('composer.json maps "%s" to a directory list, which this test does not handle.', $namespace)
            );
        }

        return $psr4;
    }

    /**
     * @param string $dir Path to scan, relative to the module root.
     * @return list<string> Distinct namespaces declared by the PHP files below $dir.
     */
    private function declaredNamespaces(string $dir): array
    {
        $namespaces = [];
        foreach ($this->phpFiles($dir) as $file) {
            if (preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $this->contents($file), $matches) === 1) {
                $namespaces[$matches[1]] = true;
            }
        }

        return array_keys($namespaces);
    }

    /**
     * @param string $file Absolute path to a PHP file declaring exactly one namespaced class.
     * @return string The fully qualified name of the class named after the file.
     */
    private function fullyQualifiedClass(string $file): string
    {
        $this->assertMatchesRegularExpression(
            '/^\s*namespace\s+([^;\s]+)\s*;/m',
            $this->contents($file),
            sprintf('%s does not declare a namespace.', $this->relativePath($file))
        );

        return sprintf('%s\\%s', $this->declaredNamespaceFor($file), basename($file, '.php'));
    }

    /**
     * @param string $file Absolute path to a PHP file.
     * @return string The single namespace the file declares.
     */
    private function declaredNamespaceFor(string $file): string
    {
        preg_match('/^\s*namespace\s+([^;\s]+)\s*;/m', $this->contents($file), $matches);

        return $matches[1];
    }

    /**
     * @param string $dir Path to scan, relative to the module root.
     * @return list<string> Absolute paths of the class files below $dir, sorted for a stable run.
     */
    private function phpFiles(string $dir): array
    {
        $root = realpath(self::MODULE_ROOT . '/' . $dir);
        $this->assertIsString($root, sprintf('"%s/" does not exist.', $dir));

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $files = [];
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * @param string $file Absolute path to a PHP file.
     * @return string The file's contents.
     */
    private function contents(string $file): string
    {
        return (string)file_get_contents($file);
    }

    /**
     * @param string $file Absolute path to a PHP file.
     * @return string The file's path relative to the module root, for test failure messages.
     */
    private function relativePath(string $file): string
    {
        $root = (string)realpath(self::MODULE_ROOT);

        return str_replace($root . '/', '', $file);
    }
}
