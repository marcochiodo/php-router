<?php

use mrblue\PhpRouter\Build;
use mrblue\PhpRouter\MethodNotAllowedException;
use mrblue\PhpRouter\RouteNotMatchException;
use mrblue\PhpRouter\Router;
use mrblue\PhpRouter\RouterException;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase {

    private static string $map_file;

    public static function setUpBeforeClass(): void {

        $root = __DIR__ . '/Fixture/Main';
        $map = Build::scan($root, 'Controller', $root);

        self::$map_file = tempnam(sys_get_temp_dir(), 'router-map-') . '.php';
        file_put_contents(
            self::$map_file,
            '<?php return ' . var_export($map, true) . ';' . PHP_EOL,
        );
    }

    public static function tearDownAfterClass(): void {
        unlink(self::$map_file);
    }

    private static function dispatch(string $method, string $uri): mixed {

        $Router = new Router('/api', self::$map_file);

        return $Router->dispatch([
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
        ]);
    }

    public function testGetCollection(): void {
        $this->assertSame(
            ['handler' => 'users', 'method' => 'get', 'params' => []],
            self::dispatch('GET', '/api/users'),
        );
    }

    public function testPostCollection(): void {
        $this->assertSame(
            ['handler' => 'users', 'method' => 'post', 'params' => []],
            self::dispatch('POST', '/api/users'),
        );
    }

    public function testGetItemCapturesParam(): void {
        $this->assertSame(
            ['handler' => 'username', 'method' => 'get', 'params' => ['username' => 'pippo']],
            self::dispatch('GET', '/api/users/pippo'),
        );
    }

    public function testPatchItem(): void {
        $this->assertSame(
            ['handler' => 'username', 'method' => 'patch', 'params' => ['username' => 'pippo']],
            self::dispatch('PATCH', '/api/users/pippo'),
        );
    }

    public function testStaticBeatsParam(): void {
        $this->assertSame(
            ['handler' => 'stats', 'method' => 'get', 'params' => []],
            self::dispatch('GET', '/api/users/stats'),
        );
    }

    public function testNestedParamsCapturedWithoutUnderscore(): void {
        $this->assertSame(
            [
                'handler' => 'id',
                'method' => 'get',
                'params' => ['username' => 'pippo', 'id' => '2'],
            ],
            self::dispatch('GET', '/api/users/pippo/addresses/2'),
        );
    }

    public function testSubresourceOfParam(): void {
        $this->assertSame(
            ['handler' => 'addresses', 'method' => 'get', 'params' => ['username' => 'pippo']],
            self::dispatch('GET', '/api/users/pippo/addresses'),
        );
    }

    public function testQueryStringIgnored(): void {
        $this->assertSame(
            ['handler' => 'users', 'method' => 'get', 'params' => []],
            self::dispatch('GET', '/api/users?page=2&sort=name'),
        );
    }

    public function testTrailingSlashIgnored(): void {
        $this->assertSame(
            ['handler' => 'users', 'method' => 'get', 'params' => []],
            self::dispatch('GET', '/api/users/'),
        );
    }

    public function testSegmentUrlDecoded(): void {
        $this->assertSame(
            ['handler' => 'username', 'method' => 'get', 'params' => ['username' => 'pippo rossi']],
            self::dispatch('GET', '/api/users/pippo%20rossi'),
        );
    }

    public function testUnknownPathThrowsRouteNotMatch(): void {

        $this->expectException(RouteNotMatchException::class);
        self::dispatch('GET', '/api/users/pippo/unknown');
    }

    public function testPathOutsidePrefixThrowsRouteNotMatch(): void {

        $this->expectException(RouteNotMatchException::class);
        self::dispatch('GET', '/outside/users');
    }

    public function testRootWithoutHandlerThrowsRouteNotMatch(): void {

        // Fixture/Main has no Controller.php, so /api has no handler
        $this->expectException(RouteNotMatchException::class);
        self::dispatch('GET', '/api');
    }

    public function testMissingMethodThrowsMethodNotAllowedWithAllowList(): void {

        try {
            self::dispatch('PUT', '/api/users');
            $this->fail('MethodNotAllowedException expected');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(['GET', 'POST'], $e->allowed);
        }
    }

    public function testStaleMapThrowsRouterException(): void {

        $map = [
            'handler' => 'Controller\\users\\doesnotexist',
            'param' => null,
            'static' => [],
        ];

        $file = tempnam(sys_get_temp_dir(), 'router-map-') . '.php';
        file_put_contents($file, '<?php return ' . var_export($map, true) . ';' . PHP_EOL);

        $Router = new Router('/api', $file);

        try {
            $Router->dispatch(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api']);
            $this->fail('RouterException expected');
        } catch (RouterException $e) {
            $this->assertStringContainsString('doesnotexist', $e->getMessage());
        } finally {
            unlink($file);
        }
    }

    public function testMissingMapFileThrowsRouterException(): void {

        $this->expectException(RouterException::class);
        new Router('/api', '/nonexistent/router-map.php');
    }

    public function testMethodIsCaseInsensitive(): void {
        $this->assertSame(
            ['handler' => 'users', 'method' => 'get', 'params' => []],
            self::dispatch('get', '/api/users'),
        );
    }
}
