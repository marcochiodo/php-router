# mrblue/php-router — Piano di implementazione

Router PHP file-based: traduce le chiamate HTTP in file su filesystem,
con parametri dinamici dichiarati come cartelle `_param` (prefisso underscore).

Sintassi parametri scelta: `_username/username.php`.
- Compatibile con l'autoloader composer standard in OGNI modalita`
  (PSR-4 runtime e classmap ottimizzata `-o`: verificato con test reale,
  nessuno skip, nessun warning, nessuna riga extra nel composer.json ospite).
- La classe dentro `_username/username.php` si chiama `username` (senza
  underscore), namespace con il segmento `_username`: PSR-4 compliant.
- Rischio collisione nullo: il prefisso `_` distingue sempre il parametro
  da una sottocartella statica.
- (Scartate: `[username]` = mai autoloadabile; `$username` = illegale su Windows.)

## Decisioni prese

- La lib fa solo questo. Uso: `require vendor/autoload.php; new Router(...); $Router->dispatch(...)`.
- **Build obbligatoria**: il Router accetta SOLO la mappa pre-buildata (path del file).
  Nessuna scansione del filesystem a runtime.
- Convenzione file: il file handler ha lo stesso nome della cartella che lo contiene
  (`users/users.php` serve `/api/users`). Niente `index.php`: i nomi in tab restano parlanti.
  Dentro le cartelle parametro il file perde l'underscore: `_username/username.php`.
- Parametri: cartella `_username/`. Max UNA cartella `_param` per livello.
  Violazione -> eccezione in build, build fallisce.
- Precedenza di match: sottocartella statica prima, poi cartella `_param`.
  (`users/stats` batte `users/pippo` se esiste la cartella `stats/`.)
- Statico + `_param` affiancati: ammessi, con WARNING a stdout durante la build
  (chi si chiama `stats` non sara` raggiungibile; per id/uuid il caso e` raro).
  Solo stdout, niente flag nella mappa.
- PHP >= 8.5.
- Metodi dei controller: di ISTANZA (il costruttore resta libero per future
  dipendenze), firma `function get(array $params)` con unico array associativo.
- `dispatch()` RITORNA il valore del metodo del controller. L'output e`
  responsabilita` dell'app (es. string -> echo, array -> json_encode, null ->
  il controller ha gia` gestito header e stampa). Documentato in README.
- Nessun match -> `RouteNotMatchException` (l'app la mappa a 404).
  Handler senza il metodo richiesto -> `MethodNotAllowedException` (l'app la
  mappa a 405, l'eccezione porta la lista dei metodi per l'header `Allow`).
  L'app mette try/catch su `dispatch()` e gestisce anche il 5xx.
- Controller caricati dall'autoloader composer standard del progetto ospite
  (PSR-4): la mappa NON contiene path di file. Se al dispatch `class_exists`
  fallisce -> `RouterException` (progetto senza autoload configurato o mappa
  non rigenerata dopo una modifica all'albero).

## Convenzioni filesystem

Albero di esempio (controllers in `src/Controller/`, PSR-4 `Controller\` -> `src/Controller/`):

```
src/Controller/
  users/
    users.php                -> /api/users
    stats/
      stats.php              -> /api/users/stats       (statico, vince sul parametro)
    _username/
      username.php           -> /api/users/{username}
      addresses/
        addresses.php        -> /api/users/{username}/addresses
        _id/
          id.php             -> /api/users/{username}/addresses/{id}
```

Contenuto dei file (sempre classe = nome file, namespace dal path):

```php
// src/Controller/users/_username/username.php
namespace Controller\users\_username;

class username {
    function get(array $params) { /* ... */ }
    function patch(array $params) { /* ... */ }
    function delete(array $params) { /* ... */ }
}
```

Esempi di match:

```
GET    /api/users                    -> Controller\users\users::get([])
POST   /api/users                    -> Controller\users\users::post([])
GET    /api/users/stats              -> Controller\users\stats\stats::get([])
GET    /api/users/pippo              -> Controller\users\_username\username::get(['username' => 'pippo'])
PATCH  /api/users/pippo              -> ...::patch(['username' => 'pippo'])
DELETE /api/users/pippo              -> ...::delete(['username' => 'pippo'])
GET    /api/users/pippo/addresses    -> Controller\users\_username\addresses\addresses::get(['username' => 'pippo'])
GET    /api/users/pippo/addresses/2  -> Controller\users\_username\addresses\_id\id::get(['username' => 'pippo', 'id' => '2'])
```

Nota params: la chiave e` il nome della cartella SENZA underscore
(`_username` -> `username`, `_id` -> `id`).

Root: un file `src/Controller/Controller.php` (classe `Controller\Controller`)
serve `/api` esatto: stessa regola "file = nome cartella" applicata alla radice.
Se assente, `/api` esatto -> RouteNotMatchException.

## Struttura della mappa (output della build)

Array annidato, un nodo per cartella. Chiavi:

- `handler`: FQCN della classe handler, o null se la cartella non ha il file omonimo.
- `param`: null oppure `['name' => ..., 'child' => nodo]` (max uno per livello;
  `name` senza underscore, es. `username`).
- `static`: array `nome_cartella => nodo` dei figli statici.

Nessun path di file: le classi arrivano dall'autoloader del progetto.

Mappa completa dell'albero di esempio:

```php
return [
    'handler' => null,   // niente Controller.php nella radice: /api esatto -> 404
    'param'   => null,
    'static'  => [
        'users' => [
            'handler' => 'Controller\\users\\users',
            'param'   => [
                'name'  => 'username',
                'child' => [
                    'handler' => 'Controller\\users\\_username\\username',
                    'param'   => null,
                    'static'  => [
                        'addresses' => [
                            'handler' => 'Controller\\users\\_username\\addresses\\addresses',
                            'param'   => [
                                'name'  => 'id',
                                'child' => [
                                    'handler' => 'Controller\\users\\_username\\addresses\\_id\\id',
                                    'param'   => null,
                                    'static'  => [],
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
                    'param'   => null,
                    'static'  => [],
                ],
            ],
        ],
    ],
];
```

## Build

Classe `mrblue\PhpRouter\Build` con entry point statico `Build::dump()` per composer scripts.

composer.json del progetto ospite:

```json
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
```

Dettagli:

- `extra` e` la sezione standard di composer.json per metadati custom: composer
  la ignora, i tool la leggono via `$event->getComposer()->getPackage()->getExtra()`.
- Composer esegue gli scripts SOLO dal composer.json radice del progetto, non
  dalle dipendenze: le due righe `scripts` le aggiunge il progetto ospite.
  Va scritto in README.
- Con `post-install-cmd` la build gira durante il `composer install` gia`
  presente nel Dockerfile: niente CMD custom, warning visibili nel build log.
- Il progetto sceglie liberamente `output` (cartella e nome file) e passa lo
  stesso path al costruttore del Router.
- `Build::dump()`:
  1. legge la config da `extra.mrblue-php-router` (path relativi alla radice
     del progetto);
  2. scansiona `controllers` ricorsivamente;
  3. ERRORI (build fallisce, exit code != 0):
     - due cartelle `_param` nello stesso livello;
     - file `.php` che non sia il file omonimo della cartella (es. `foo.php`
       dentro `users/`: file orfano, la convenzione e` violata);
  4. WARNING a stdout (build OK):
     - cartella statica affiancata a `_param` (il parametro con quel valore
       e` irraggiungibile);
  5. scrive `output` come file `<?php return [...];` (var_export,
     opcache-friendly), creando la cartella di output se manca.

## Router (runtime)

```php
final class Router {
    public function __construct(
        private string $api_prefix,   // es. '/api'
        string $map_file,             // es. __DIR__.'/var/router-map.php'
    );                                // RouterException se manca/invalido

    public function dispatch(array $server): mixed;  // il $_SERVER superglobal
}
```

`dispatch(array $server)` (implementazione reale: legge `REQUEST_METHOD` e
`REQUEST_URI` dal superglobal passato, default `$_SERVER`; supporta
`HTTP_X_HTTP_METHOD_OVERRIDE` solo come rimedio all'hack HEAD dei client
interni):
1. `parse_url` per togliere la query string; strip di `api_prefix`;
   normalizza trailing slash; `rawurldecode` dei segmenti.
2. Passeggiata sulla mappa: per ogni segmento prima `static[$seg]`,
   poi `param` (cattura `params[name] = segmento`), altrimenti
   `throw new RouteNotMatchException`.
3. Fine path: se `handler` null -> `RouteNotMatchException`.
4. `class_exists($node['handler'])` -> se false, `RouterException`
   (autoload non configurato o mappa obsoleta: rilanciare la build).
5. Metodo: `strtolower($method)`; se non esiste sulla classe ->
   `MethodNotAllowedException` (porta la lista dei metodi disponibili
   come proprieta` pubblica `$e->allowed`, per l'header `Allow`).
6. `return (new $class)->$method($params);`

Il Router NON stampa nulla e NON setta header/http_response_code.
Esempio canonico d'uso lato app (va in README):

```php
require 'vendor/autoload.php';

use mrblue\PhpRouter\{Router, RouteNotMatchException, MethodNotAllowedException};

$Router = new Router('/api', __DIR__ . '/var/router-map.php');

try {
    $result = $Router->dispatch($_SERVER);

    if (is_string($result)) {
        echo $result;
    } elseif (is_array($result) || is_object($result)) {
        header('Content-Type: application/json');
        echo json_encode($result);
    }
    // null: il controller ha gia` gestito output e header
} catch (RouteNotMatchException) {
    http_response_code(404);
} catch (MethodNotAllowedException $e) {
    header('Allow: ' . implode(', ', $e->allowed));
    http_response_code(405);
} catch (Throwable $e) {
    http_response_code(500);
}
```

## Test (`test/`)

PHPUnit in `require-dev`, eseguito dentro immagine docker `php:8.5-cli-alpine`
(niente PHP locale): `docker-run.sh` aggiornato con composer install +
`vendor/bin/phpunit`.

Fixture: l'albero della sezione "Convenzioni filesystem", piu`:
- coppia `_foo/_bar` fratelli -> errore di build;
- file orfano (`foo.php` in cartella `users/`) -> errore di build;
- `stats/` + `_username/` -> warning a stdout, build OK.

Test:
1. Build sulla fixture corretta: mappa generata identica a quella attesa.
2. Errori di build: eccezione sui due casi invalidi.
3. Warning: catturato a stdout sul caso static+param.
4. Match: tutti gli esempi della sezione match, compreso static che batte
   param, 404 su path sconosciuto, 405 su metodo mancante (con lista `Allow`),
   root senza `Controller.php` -> 404.
5. Params: chiavi senza underscore, valori catturati su due livelli
   (`username`, `id`), incluso `rawurldecode`.
6. `RouterException` se la mappa punta a una classe inesistente.

- `src/Router.php`, `src/Build.php`, `src/RouterException.php`,
  `src/RouteNotMatchException.php`, `src/MethodNotAllowedException.php`,
  `src/BuildException.php` — implementati.
- `composer.json` — `php: >=8.5`, `phpunit/phpunit` in require-dev,
  `autoload-dev` PSR-4 `Controller\` -> `test/Fixture/` (fixture classi).
- `phpunit.xml` — creato.
- `test/` — `BuildTest.php` (5 test) + `RouterTest.php` (17 test, include
  static>param, 404/405, params senza underscore, rawurldecode, query string,
  override metodo). Eliminate le fixture del modello `Item`.
- `docker-test.sh` — nuovo: composer install + phpunit in `php:8.5-cli-alpine`,
  niente PHP locale. Esito: 22/22 test verdi.
- `README.md` — completo: convenzioni, matching rules, setup (extra+scripts+
  autoload), esempio d'uso con output/404/405/500, tabella eccezioni,
  troubleshooting build/runtime. Nessun riferimento ad agenti.

## Fuori scope (volutamente)

- Runtime senza build (nessun fallback di scansione).
- Middleware, DI container, body parsing, gestione output: in carico all'app.
- CORS/preflight, HEAD automatico.
- Composer plugin per auto-registrare gli scripts.
