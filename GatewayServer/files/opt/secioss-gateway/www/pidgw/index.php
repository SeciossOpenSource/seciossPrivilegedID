<?php

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcacheSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;

require '../server/vendor/autoload.php';

require_once 'Smarty/Smarty.class.php';

$memcache = new Memcache();
$memcache->addServer('localhost', 11211);

$storage = new NativeSessionStorage([], new MemcacheSessionHandler($memcache));
$session = new Session($storage);
$session->start();

$userid = $session->get('userid');
if (!$userid) {
    header('HTTP/1.1 403 Forbidden');
    exit(0);
}

$conns = $session->get('conns');
if (!$conns) {
    $conns = [];
}

$idconnection = null;
if (isset($_GET['idconn'])) {
    $idconnection = $_GET['idconn'];
    if (!isset($conns[$idconnection])) {
        $idconnection = null;
    }
}
if (!$idconnection) {
    header('HTTP/1.1 403 Forbidden');
    exit(0);
}

$smarty = new Smarty();
$smarty->template_dir = 'templates';
$smarty->compile_dir = 'templates_c';

$smarty->assign('server_name', $_SERVER['SERVER_NAME']);
$smarty->assign('idconnection', $idconnection);

$smarty->display('index.tpl');
