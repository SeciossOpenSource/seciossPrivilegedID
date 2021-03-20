<?php
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\Handler;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\MemcacheSessionHandler;

require '../server/vendor/autoload.php';

require_once 'Log.php';
require_once '../util/guac_utils.php';
require_once 'Secioss/Crypt.php';
require_once 'Secioss/Util.php';
use Secioss\Crypt;
use Secioss\Util;

// Util
$util = new GuacUtils();

// Time Zone設定
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'Asia/Tokyo');
}

// Log
$logid = 'SeciossGateway';
$log = &Log::singleton('syslog', LOG_LOCAL5, $logid);
$log->info('Start secioss gateway');

// SAML認証
if (isset($_GET['id'])) {
    $id = $_GET['id'];
} else {
    $log->err('privileged id does not exist.');
    $util->response(400);
}

$authuser = $_SERVER['REMOTE_USER'];
$client_ip = $_SERVER['REMOTE_ADDR'];
$log->info("access from $client_ip");

// パラメーター
$conf = $util->get_conf();
$gateway_privatekey = $conf['gateway']['privatekey'];

// 必須パラメーター存在確認
if (!$authuser) {
    $log->err('saml:sp:NameID does not exist.');
    $util->response(400);
}

// SAMLレスポンスからデータ取得
$server = null;
$port = 0;
$protocol = 'ssh';
$database = null;

$basedn = $conf['gateway']['ldap_basedn'];
$ldap = @ldap_connect($conf['gateway']['ldap_uri']);
@ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
if (!@ldap_bind($ldap, $conf['gateway']['ldap_binddn'], $conf['gateway']['ldap_bindpw'])) {
    $log->err("Can't bind ldap server.: ".ldap_error($ldap));
    $util->response(400);
}

$res = @ldap_search($ldap, $basedn, '(&(&(objectClass=seciossIamAccount)(objectClass=seciossPerson))(uid='.$authuser.'))', ['seciossprivilegerole']);
if ($res === false) {
    $log->err("Can't search user $authuser.: ".ldap_error($ldap));
    $util->response(400);
}

$user = null;
$attrs = [];
$params = null;
if (ldap_count_entries($ldap, $res)) {
    $entries = ldap_get_entries($ldap, $res);
    if (isset($entries[0]['seciossprivilegerole'])) {
        $revokeRoles = [];
        for ($i = 0; $i < $entries[0]['seciossprivilegerole']['count']; $i++) {
            $prole = json_decode($entries[0]['seciossprivilegerole'][0], true);
            if ($prole['privilegetype'] == 'time_limitation') {
                $now_time = strftime("%Y/%m/%d %H:%M");
                if ($prole['stardate'] > $now_time) {
                    continue; 
                } elseif ($now_time > $prole['expirationdate']) {
                    $revokeRoles[] = $entries[0]['seciossprivilegerole'][0];
                    continue;
                } else {
                    $user = $prole['privilegedid'];
                }
            } elseif ($prole['privilegetype'] == 'infinite') {
                $user = $prole['privilegedid'];
            }
            if ($user && strcasecmp($prole['privilegedid'].'/'.$prole['serviceid'], $id)) {
                continue;
            }

            $res = @ldap_search($ldap, $basedn, '(&(&(objectClass=seciossIamAccount)(objectClass=seciossPerson))(uid='.$id.'))', ['seciossencryptedpassword', 'seciossencryptedprivatekey']);
            if ($res === false) {
                $log->err("$authuser can't search privileged id $id.: ".ldap_error($ldap));
                $util->response(400);
            }
            if (ldap_count_entries($ldap, $res)) {
                $entries2 = ldap_get_entries($ldap, $res);
                if (isset($entries2[0]['seciossencryptedpassword'])) {
                    $attrs['seciossEncryptedPassword'] = $entries2[0]['seciossencryptedpassword'][0];
                }
                if (isset($entries2[0]['seciossencryptedprivatekey'])) {
                    $attrs['seciossEncryptedPrivateKey'] = $entries2[0]['seciossencryptedprivatekey'][0];
                }

                $res = @ldap_search($ldap, 'ou=Metadata,'.$basedn, '(&(&(objectClass=seciossSamlMetadata)(seciossSamlMetadataType=saml20-sp-remote))(cn='.$prole['serviceid'].'))', ['seciosssamlserializeddata']);
                if ($res === false) {
                    $log->err("$authuser can't search target $id.");
                    $util->response(400);
                }
                if (ldap_count_entries($ldap, $res)) {
                    $entries3 = ldap_get_entries($ldap, $res);
                    $prop = unserialize($entries3[0]['seciosssamlserializeddata'][0]);
                    if (isset($prop['authproc'][60])) {
                        $params = json_decode(base64_decode($prop['authproc'][60]['guac']), true);
                    }
                }
            }
            break;
        }
        if (count($revokeRoles)) {
            $res = ldap_mod_del($ldap, $entries[0]['dn'][0], ['seciossprivilegerole' => $revokeRoles]);
            if ($res === false) {
                $log->err("$authuser can't revoke roles(".join(' ', $revokeRoles).")");
            }
        }
    }
}
ldap_unbind($ldap);

if (!$params) {
    $log->err("$authuser can't login to $id");
    $util->response(400);
}

foreach ($params as $key => $value) {
    // パラメーターに設定
    switch ($key) {
        case 'hostname':
            $server = $value;
            break;
        case 'port':
            $port = $value;
            break;
        case 'protocol':
            $protocol = $value;
            break;
        case 'database':
            $database = $value;
            break;
    }
}

$aeskey = Crypt::getSecretKey($conf['gateway']['keyfile']);

$password = '';
if (isset($attrs['seciossEncryptedPassword']) && $attrs['seciossEncryptedPassword']) {
    $password = Util::decrypt($attrs['seciossEncryptedPassword'], null, $aeskey);
}
$log->info("$authuser login to $id");

// 秘密鍵・パスフレーズ
$private_key = '';
$passphrase = '';
$publick_key = '';
if (isset($attrs['seciossEncryptedPrivateKey']) && $attrs['seciossEncryptedPrivateKey']) {
    $key_data = $attrs['seciossEncryptedPrivateKey'];
    // data分割
    if (preg_match('/^\{.*\}(.*)\{passphrase\}(.*)\{publickey\}(.*)$/', $key_data, $matches)) {
        $encrypt_privatekey = $matches[1];
        $encrypt_passphrase = $matches[2];
        $public_key = $matches[3];
    } else {
        $log->info('Failed to acquire secret key and passphrase.');
        $util->response(400);
    }

    if ($encrypt_passphrase) {
        // パスフレーズ取得
        $passphrase = Util::decrypt($encrypt_passphrase, $aeskey);
        if ($passphrase !== false) {
            $log->info('Confirm existence of passphrase.');
        } else {
            $log->info('passphrase is invalid.');
            $util->response(400);
        }
    }

    // 秘密鍵取得
    $private_key = $util->get_private_key($encrypt_privatekey, $gateway_privatekey);
    if ($private_key !== false) {
        $log->info('Confirm existence of private key.');
    } else {
        $log->info('private key is invalid.');
        $util->response(400);
    }
}

$memcache = new Memcache();
$memcache->addServer('localhost', 11211);

$storage = new NativeSessionStorage([], new MemcacheSessionHandler($memcache));
$session = new Session($storage);
$session->start();

if ($authuser) {
    if ($session->get('userid')) {
        if ($session->get('userid') !== $authuser) {
            $util->response(400);
        }
    } else {
        $session->set('userid', $authuser);
    }
} else {
    $util->response(400);
}

// レコーディング
$recording_log = null;
if (isset($params['recording-path']) && isset($params['recording-name'])) {
    $recording_log = $params['recording-path'].'/'.str_replace('${GUAC_USERNAME}', $authuser, str_replace('${GUAC_TIME}', date('His'), str_replace('${GUAC_DATE}', date('Ymd'), $params['recording-name'])));
}

$conns = $session->get('conns');
if (!$conns) {
    $conns = [];
}

$idconnection = $util->get_connect_id();
$conns[$idconnection] = ['protocol' => $protocol, 'server' => $server, 'port' => $port, 'user' => $user];
if ($private_key) {
    $conns[$idconnection]['private_key'] = $private_key;
    $conns[$idconnection]['public_key'] = $public_key;
    if ($passphrase) {
        $conns[$idconnection]['passphrase'] = $passphrase;
    }
} else {
    $conns[$idconnection]['password'] = $password;
}
if ($database) {
    $conns[$idconnection]['database'] = $database;
}
if ($recording_log) {
    $conns[$idconnection]['recording_log'] = $recording_log;
}
$session->set('conns', $conns);

header("Location: index.php?idconn=$idconnection");

exit(0);
