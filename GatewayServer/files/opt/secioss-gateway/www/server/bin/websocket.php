<?php
/*
 *
 *  websocket.php
 *
 *  @author     Kaoru Sekiguchi <sekiguchi.kaoru@secioss.co.jp>
 *  @copyright  2020 SECIOSS, INC.
 *
*/

use MyApp\Servidorsocket;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\Session\SessionProvider;
use Ratchet\WebSocket\WsServer;
use Symfony\Component\HttpFoundation\Session\Storage\Handler;

require dirname(__DIR__).'/vendor/autoload.php';

$conf = parse_ini_file(dirname(__DIR__).'/../conf/config.ini', true);
$memcache = new Memcache();
if (isset($conf) && isset($conf['gateway']['memcache_host'])) {
    $hosts = explode(' ', $conf['gateway']['memcache_host']);
    foreach ($hosts as $host) {
        if (strpos($host, ':') !== false) {
            list($host, $port) = explode(':', $host);
        } else {
            $port = 11211;
        }
        $memcache->addServer($host, $port);
    }
} else {
    $memcache->addServer('localhost', 11211);
}

$port = 8090;
if ($argc > 1) {
    $port = $argv[1];
}
$server = IoServer::factory(
    new HttpServer(
        new SessionProvider(
            new WsServer(
                new Servidorsocket()
            ),
            new Handler\MemcacheSessionHandler($memcache)
        )
    ),
    $port
);

$server->run();
