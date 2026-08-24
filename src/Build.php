<?php

namespace mrblue\PhpRouter;

final class Build {

    /**
     * Composer script entry point (post-install-cmd / post-update-cmd).
     * Reads configuration from the root package's extra.mrblue-php-router.
     *
     * @param object $event composer event (duck-typed to avoid a hard composer dependency)
     */
    public static function dump(object $event): void {

        $extra = $event->getComposer()->getPackage()->getExtra();
        $config = $extra['mrblue-php-router'] ?? null;

        if (!is_array($config)) {
            throw new BuildException(
                'Missing "extra.mrblue-php-router" configuration in composer.json'
            );
        }

        foreach (['controllers', 'namespace', 'output'] as $key) {
            if (empty($config[$key]) || !is_string($config[$key])) {
                throw new BuildException(
                    "Missing or invalid \"extra.mrblue-php-router.$key\" in composer.json"
                );
            }
        }

        try {
            $map = self::scan(
                $config['controllers'],
                trim($config['namespace'], '\\'),
                $config['controllers'],
            );
        } catch (BuildException $e) {
            fwrite(STDERR, 'php-router build FAILED: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }

        $output_dir = dirname($config['output']);
        if (!is_dir($output_dir) && !mkdir($output_dir, 0777, true)) {
            fwrite(STDERR, "php-router build FAILED: cannot create directory $output_dir" . PHP_EOL);
            exit(1);
        }

        $bytes = file_put_contents(
            $config['output'],
            '<?php return ' . var_export($map, true) . ';' . PHP_EOL,
        );

        if ($bytes === false) {
            fwrite(STDERR, 'php-router build FAILED: cannot write ' . $config['output'] . PHP_EOL);
            exit(1);
        }

        echo 'php-router: route map written to ' . $config['output'] . PHP_EOL;
    }

    /**
     * Scans the controllers folder and returns the route map.
     *
     * @throws BuildException on convention violations (two _param siblings, orphan php file)
     */
    public static function scan(string $path, string $namespace, string $root): array {

        $items = scandir($path);
        if ($items === false) {
            throw new BuildException("Cannot scan directory: $path");
        }

        $node = ['handler' => null, 'param' => null, 'static' => []];
        $folder_name = basename($path);
        $param_dir = null;

        foreach ($items as $item) {

            if ($item === '.' || $item === '..') {
                continue;
            }

            $item_path = $path . '/' . $item;

            if (is_dir($item_path)) {

                if (str_starts_with($item, '_')) {
                    if ($param_dir !== null) {
                        throw new BuildException(
                            "Two parameter folders in '$path': '$param_dir' and '$item'. Only one _param folder per level is allowed"
                        );
                    }
                    $param_dir = $item;
                } else {
                    $ns = $namespace === '' ? $item : $namespace . '\\' . $item;
                    $node['static'][$item] = self::scan($item_path, $ns, $root);
                }
            } elseif (is_file($item_path)) {

                if (!str_ends_with($item, '.php')) {
                    continue;
                }

                $expected = str_starts_with($folder_name, '_')
                    ? substr($folder_name, 1) . '.php'
                    : $folder_name . '.php';

                if ($item !== $expected) {
                    throw new BuildException(
                        "Orphan file '$item_path': controller files must be named after their folder ('$expected')"
                    );
                }

                if (basename($item_path, '.php') === basename($root)) {
                    // root controller file: src/Controller/Controller.php
                    $node['handler'] = $namespace;
                } else {
                    $node['handler'] = $namespace . '\\' . basename($item, '.php');
                }
            }
        }

        if ($param_dir !== null) {
            $ns = $namespace === '' ? $param_dir : $namespace . '\\' . $param_dir;
            $node['param'] = [
                'name' => substr($param_dir, 1),
                'child' => self::scan($path . '/' . $param_dir, $ns, $root),
            ];
        }

        if ($param_dir !== null) {
            foreach (array_keys($node['static']) as $static_name) {
                fwrite(
                    STDERR,
                    "php-router WARNING: '$path' contains both '$static_name/' and '$param_dir/'; " .
                    "the static route wins, so a parameter value of '$static_name' will never match" . PHP_EOL,
                );
            }
        }

        ksort($node['static']);
        return $node;
    }
}
