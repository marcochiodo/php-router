# mrblue/php-router

A minimal file-based router for PHP: HTTP requests are translated into
controller classes living on the filesystem, with dynamic segments declared
as `_param` folders. The library does nothing else: no middleware, no body
parsing, no output handling.

## How it works

1. Controllers live in a folder tree that mirrors the API URL structure.
2. `composer install` runs the build step, which scans the tree and writes a
   static route map (a PHP file returning an array).
3. At runtime the router walks the map, instantiates the matched controller
   (through the standard composer autoloader) and calls the method matching
   the HTTP verb. It returns the controller's return value; sending output,
   headers and status codes is the application's job.

## Conventions

- The file handling a route has the **same name as its folder**:
  `users/users.php` serves `/api/users`.
- Dynamic segments are folders prefixed with an underscore:
  `_username/username.php` serves `/api/users/{username}`.
  The file drops the underscore (`username.php`), the PHP class is
  `username`, the namespace keeps it (`Controller\users\_username`).
- The parameter key in `$params` is the folder name without underscore:
  `_username` -> `'username'`.
- A root controller file named after the controllers folder itself
  (e.g. `src/Controller/Controller.php`, class `Controller\Controller`)
  serves the bare API prefix (`/api`).
- No `index.php`, no support files inside the controllers tree: every `.php`
  file must be the handler of its folder. Orphan files fail the build.

Why `_param` and not `[param]`: PHP class names cannot start with `[`, so
bracket folders are unreachable by the composer autoloader (verified: PSR-4
runtime loses the brackets, optimized classmap skips non-compliant files).
The underscore prefix is PSR-4 clean in every composer mode.

### Matching rules

- Static segments win over `_param` segments: `users/stats/stats.php`
  serves `/api/users/stats` even when `_username/` exists.
- At most **one** `_param` folder per level (the router would not know which
  parameter name to assign otherwise). Violation: build fails.
- A static folder next to a `_param` folder is allowed, but the build prints
  a warning: that static name can never occur as a parameter value.
- HTTP method names map to controller methods, case-insensitive:
  `GET -> get(array $params)`, `POST -> post(...)`, etc.

### Example tree

```
src/Controller/
  Controller.php           -> /api                              (optional)
  users/
    users.php              -> /api/users
    stats/
      stats.php            -> /api/users/stats
    _username/
      username.php         -> /api/users/{username}
      addresses/
        addresses.php      -> /api/users/{username}/addresses
        _id/
          id.php           -> /api/users/{username}/addresses/{id}
```

A controller file:

```php
<?php

namespace Controller\users\_username;

class username {

    function get(array $params) {
        return User::find($params['username']);
    }

    function patch(array $params) {
        // ...
    }

    function delete(array $params) {
        // ...
    }
}
```

Controller methods are instance methods (the constructor stays available for
future dependencies) and receive a single associative array of path
parameters.

## Installation and setup

Install:

```
composer require mrblue/php-router
```

In your project's `composer.json` add three things:

```json
{
    "autoload": {
        "psr-4": {
            "Controller\\": "src/Controller/"
        }
    },
    "extra": {
        "mrblue-php-router": {
            "controllers": "src/Controller",
            "namespace": "Controller",
            "output": "var/router-map.php"
        }
    },
    "scripts": {
        "post-install-cmd": "mrblue\\PhpRouter\\Build::dump",
        "post-update-cmd": "mrblue\\PhpRouter\\Build::dump"
    }
}
```

- `autoload`: standard PSR-4 for your controllers. Required.
- `extra.mrblue-php-router`: build configuration, read by the build script.
  Paths are relative to the project root. `output` is wherever you like.
- `scripts`: runs the build on every install/update, so inside your
  Dockerfile nothing changes: it happens during the `composer install` you
  already run. Composer executes script handlers only from the **root**
  composer.json, which is why these two lines belong to your project, not to
  the library.

The build prints route-map diagnostics to stdout/stderr and fails the whole
composer install on convention violations (see Troubleshooting).

## Usage

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use mrblue\PhpRouter\Router;
use mrblue\PhpRouter\RouteNotMatchException;
use mrblue\PhpRouter\MethodNotAllowedException;

$Router = new Router('/api', __DIR__ . '/var/router-map.php');

try {
    $result = $Router->dispatch($_SERVER);

    if (is_string($result)) {
        echo $result;
    } elseif (is_array($result) || is_object($result)) {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
    // null: the controller handled output and headers itself
} catch (RouteNotMatchException) {
    http_response_code(404);
} catch (MethodNotAllowedException $e) {
    header('Allow: ' . implode(', ', $e->allowed));
    http_response_code(405);
} catch (Throwable) {
    http_response_code(500);
}
```

`dispatch(array $server): mixed` takes the `$_SERVER` superglobal (or any
array with `REQUEST_METHOD` and `REQUEST_URI` keys) and returns whatever the
controller method returned. The router never echoes, never sets headers,
never calls `http_response_code`: output policy belongs to the application.

### Exceptions

| Exception | When | Typical mapping |
|---|---|---|
| `RouteNotMatchException` | no route for the path, path outside the API prefix, or route node without a handler | 404 |
| `MethodNotAllowedException` | route matched but the HTTP method is missing; `$e->allowed` lists the available methods | 405 + `Allow` header |
| `RouterException` | missing/invalid map file, handler class not autoloadable (stale map: re-run the build) | 500 |
| `BuildException` | convention violation during build | build failure |

All extend `mrblue\PhpRouter\RouterException` (which extends `\RuntimeException`).

## Troubleshooting

Build errors (composer install fails):

- `Missing "extra.mrblue-php-router" configuration` ->
  add the `extra` block shown above to the root composer.json.
- `Two parameter folders in '<path>'` ->
  only one `_param` folder per level; merge or restructure the routes.
- `Orphan file '<path>'` ->
  every `.php` inside the controllers tree must be named after its folder.
  Move support classes outside the controllers tree.

Build warnings (install succeeds, printed to stderr):

- `'<path>' contains both '<static>/' and '<_param>/'; the static route wins` ->
  requests with a segment equal to the static name will always hit the static
  route; that value can never reach the parameter route. Usually fine when
  parameters are ids/uuids; rename the static folder if it is not.

Runtime errors:

- `Handler class '...' not found. Check the project autoload and re-run the
  route build.` -> either `autoload.psr-4` for the controllers namespace is
  missing, or the controller tree changed after the last build. Run
  `composer install` (or `composer dump-autoload`) again.

## Development

Tests run inside a disposable docker container, no local PHP needed:

```
./docker-test.sh
```

Requires PHP >= 8.5.
