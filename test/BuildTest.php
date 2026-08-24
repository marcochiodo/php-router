<?php

use mrblue\PhpRouter\Build;
use mrblue\PhpRouter\BuildException;
use PHPUnit\Framework\TestCase;

class BuildTest extends TestCase {

    private const MAIN = __DIR__ . '/Fixture/Main';

    public function testScanBuildsExpectedMap(): void {

        $map = Build::scan(self::MAIN, 'Controller', self::MAIN);

        $expected = [
            'handler' => null,
            'param' => null,
            'static' => [
                'users' => [
                    'handler' => 'Controller\\users\\users',
                    'param' => [
                        'name' => 'username',
                        'child' => [
                            'handler' => 'Controller\\users\\_username\\username',
                            'param' => null,
                            'static' => [
                                'addresses' => [
                                    'handler' => 'Controller\\users\\_username\\addresses\\addresses',
                                    'param' => [
                                        'name' => 'id',
                                        'child' => [
                                            'handler' => 'Controller\\users\\_username\\addresses\\_id\\id',
                                            'param' => null,
                                            'static' => [],
                                        ],
                                    ],
                                    'static' => [],
                                ],
                            ],
                        ],
                    ],
                    'static' => [
                        'stats' => [
                            'handler' => 'Controller\\users\\stats\\stats',
                            'param' => null,
                            'static' => [],
                        ],
                    ],
                ],
            ],
        ];

        $this->assertSame($expected, $map);
    }

    public function testScanKeepsStaticSiblingOfParam(): void {

        // static + _param siblings are allowed: the map keeps both nodes
        // (the static-vs-param precedence warning is printed to STDERR at build time)
        $map = Build::scan(self::MAIN, 'Controller', self::MAIN);

        $this->assertArrayHasKey('stats', $map['static']['users']['static']);
        $this->assertSame('username', $map['static']['users']['param']['name']);
    }

    public function testScanFailsOnTwoParamFoldersSameLevel(): void {

        $this->expectException(BuildException::class);
        $this->expectExceptionMessageMatches('/two parameter folders/i');

        Build::scan(__DIR__ . '/Fixture/Bad/two-params', 'Controller', __DIR__ . '/Fixture/Bad/two-params');
    }

    public function testScanFailsOnOrphanPhpFile(): void {

        $this->expectException(BuildException::class);
        $this->expectExceptionMessageMatches('/orphan file/i');

        Build::scan(__DIR__ . '/Fixture/Bad/orphan', 'Controller', __DIR__ . '/Fixture/Bad/orphan');
    }

    public function testMapFileGeneratedByBuildIsLoadableAndIdentical(): void {

        $map = Build::scan(self::MAIN, 'Controller', self::MAIN);

        $file = tempnam(sys_get_temp_dir(), 'router-map-') . '.php';
        file_put_contents($file, '<?php return ' . var_export($map, true) . ';' . PHP_EOL);

        $loaded = include $file;
        unlink($file);

        $this->assertSame($map, $loaded);
    }
}
