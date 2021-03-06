<?php

require_once 'Log.php';
require_once dirname(__FILE__).'/../www/util/guac_utils.php';
require_once 'Secioss/Crypt.php';
require_once 'Secioss/Util.php';
use Secioss\Crypt;
use Secioss\Util;

// Time Zone設定
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'Asia/Tokyo');
}
// Log
$logid = 'GUACAMOLE_REMOTE_SYNC';
$log = Log::singleton('syslog', LOG_LOCAL4, $logid);

$util = new GuacUtils();
$conf = $util->get_conf();
$ldap_uri = $conf['gateway']['ldap_uri'];
$ldap_binddn = $conf['gateway']['ldap_binddn'];
$ldap_bindpw = $conf['gateway']['ldap_bindpw'];
$ldap_basedn = $conf['gateway']['ldap_basedn'];

$aeskey = Crypt::getSecretKey($conf['gateway']['keyfile']);

$pdo = null;
$guac_conf = file_get_contents('/etc/guacamole/guacamole.properties');
if (preg_match_all("/(mysql|postgresql)-([^:]+): ([^\n]+)/", $guac_conf, $matches)) {
    $db_type = $matches[1][0];
    $db_opts = [];
    for ($i = 0; $i < count($matches[2]); $i++) {
        $db_opts[$matches[2][$i]] = $matches[3][$i];
    }
    $pdo = new PDO("$db_type:host=".$db_opts['hostname'].";dbname=".$db_opts['database'].(isset($db_opts['port']) ? ";port=".$db_opts['port'] : ''), $db_opts['username'], $db_opts['password']);
} else {
    print("guacamole has no database\n");
    exit(1);
}

$ldap = @ldap_connect($ldap_uri);
if (!$ldap) {
    $log->err("Can't connect $ldap_uri");
    exit(1);
}
@ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);

if (!@ldap_bind($ldap, $ldap_binddn, $ldap_bindpw)) {
    $log->err("Can't bind $ldap_uri: ".ldap_error($ldap));
    exit(1);
}

$target_list = [];
$res = @ldap_search($ldap, $ldap_basedn, '(&(objectClass=seciossSamlMetadata)(seciossSamlMetadataType=saml20-sp-remote))', ['cn', 'seciosssamlserializeddata']);
if ($res == false) {
    $log->err("Failed to search targets: ".ldap_error($ldap));
    exit(1);
}
$entries = ldap_get_entries($ldap, $res);
for ($i = 0; $i < $entries['count']; $i++) {
    $target_raw = unserialize($entries[$i]['seciosssamlserializeddata'][0]);
    if (!isset($target_raw['authproc'][60]['guac'])) {
        continue;
    }

    $target_list[$entries[$i]['cn'][0]] = ['name' => $target_raw['description'], 'guac' => json_decode(base64_decode($target_raw['authproc'][60]['guac']))];
}

$pid_list = [];
$res = @ldap_search($ldap, $ldap_basedn, '(&(objectClass=inetOrgPerson)(seciossaccountstatus=active)(seciossPrivilegedIdType=*))');
if ($res == false) {
    $log->err("Failed to search privileged ids: ".ldap_error($ldap));
    exit(1);
}
$entries = ldap_get_entries($ldap, $res);
for ($i = 0; $i < $entries['count']; $i++) {
    $data = ['id' => $entries[$i]['seciossloginid'][0], 'service_id' => $entries[$i]['seciossallowedservice'][0]];
    if (isset($entries[$i]['seciossencryptedprivatekey'])) {
        if (preg_match('/^\{.*\}(.*)\{passphrase\}(.*)\{publickey\}(.*)$/', $entries[$i]['seciossencryptedprivatekey'], $matches)) {
            $encrypt_privatekey = $matches[1];
            $encrypt_passphrase = $matches[2];
            $public_key = $matches[3];
            $private_key = $util->get_private_key($encrypt_privatekey, $conf['gateway']['privatekey']);
            if ($private_key) {
                $data['private-key'] = $private_key;
                if ($encrypt_passphrase) {
                    $passphrase = Util::decrypt($encrypt_passphrase, null, $aeskey);
                    $data['passphrase'] = $passphrase;
                }
            }
        }
    }
    if (!isset($data['private-key']) && isset($entries[$i]['seciossencryptedpassword'])) {
        $data['password'] = Util::decrypt($entries[$i]['seciossencryptedpassword'][0], null, $aeskey);
    }
    $pid_list[$entries[$i]['uid'][0]] = $data;
}

foreach ($pid_list as $key => $data) {
    $guac = $target_list[$data['service_id']]['guac'];
    if (!preg_match('/^(rdp|ssh)$/', $guac->protocol)) {
        continue;
    }

    try {
        $connection_id = null;
        $sql = 'SELECT * FROM guacamole_connection WHERE connection_name = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $key, PDO::PARAM_STR);
        $stmt->execute();
        $connections = $stmt->fetchAll();
        if (count($connections)) {
            $connection_id = $connections[0]['connection_id'];
            $pid_list[$key]['connection_id'] = $connection_id;
        } else {
            $sql = 'INSERT INTO guacamole_connection(connection_name, protocol) VALUES(?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $key, PDO::PARAM_STR);
            $stmt->bindValue(2, $guac->protocol, PDO::PARAM_STR);
            $stmt->execute();

            $sql = 'SELECT * FROM guacamole_connection WHERE connection_name = ?';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $key, PDO::PARAM_STR);
            $stmt->execute();
            $connections = $stmt->fetchAll();
            if (count($connections)) {
                $connection_id = $connections[0]['connection_id'];
                $pid_list[$key]['connection_id'] = $connection_id;
            }
        }
        if (!$connection_id) {
            continue;
        }

        $sql = 'DELETE FROM guacamole_connection_parameter WHERE connection_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $connection_id, PDO::PARAM_INT);
        $stmt->execute();

        $params = ['hostname' => $guac->hostname, 'port' => $guac->port, 'username' => $data['id']];
        if (isset($data['password'])) {
            $params['password'] = $data['password'];
        }
        foreach ($params as $key => $value) {
            $sql = 'INSERT INTO guacamole_connection_parameter(connection_id, parameter_name, parameter_value) VALUES(?, ?, ?)';
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $connection_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $key, PDO::PARAM_STR);
            $stmt->bindValue(3, $value, PDO::PARAM_STR);
            $stmt->execute();
        }
    } catch (PDOException $e) {
        $log->err("Failed to udpate guacamole connection: ".$e->getMessage());
        exit(1);
    }
}

$res = @ldap_search($ldap, $ldap_basedn, '(&(objectClass=inetOrgPerson)(seciossaccountstatus=active)(!(seciossPrivilegedIdType=*)))', ['uid', 'seciossprivilegerole']);
if ($res == false) {
    $log->err("Failed to search users: ".ldap_error($ldap));
    exit(1);
}
$entries = ldap_get_entries($ldap, $res);
for ($i = 0; $i < $entries['count']; $i++) {
    if (!isset($entries[$i]['seciossprivilegerole'])) {
        continue;
    }

    try {
        $sql = 'SELECT entity_id FROM guacamole_entity WHERE name = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $entries[$i]['uid'][0], PDO::PARAM_STR);
        $stmt->execute();
        $entities = $stmt->fetchAll();
        if (count($entities)) {
            $entity_id = $entities[0]['entity_id'];
        } else {
            continue;
        }

        $sql = 'DELETE FROM guacamole_connection_permission WHERE entity_id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $entity_id, PDO::PARAM_STR);
        $stmt->execute();

        for ($j = 0; $j < $entries[$i]['seciossprivilegerole']['count']; $j++) {
            $role_json = json_decode($entries[$i]['seciossprivilegerole'][$j]);
            $pid = $role_json->privilegedid.'/'.$role_json->serviceid;
            if ($role_json->privilegetype == 'infinite') {
            } elseif ($role_json->privilegetype == 'time_limitation') {
                $now = time();
                if (property_exists($role_json, 'startdate')) {
                    $startdate = $role_json->startdate;
                    $startdate = str_replace('/', '-', $startdate).':00';
                    if ($now < strtotime($startdate)) {
                        continue;
                    }
                }
                if (property_exists($role_json, 'expirationdate')) {
                    $expirationdate = $role_json->expirationdate;
                    $expirationdate = str_replace('/', '-', $expirationdate).':59';
                    if ($now > strtotime($expirationdate)) {
                        continue;
                    }
                }
            } else {
                continue;
            }
            if (isset($pid_list[$pid]) && isset($pid_list[$pid]['connection_id'])) {
                $connection_id = $pid_list[$pid]['connection_id'];
            } else {
                continue;
            }

            $sql = "INSERT INTO guacamole_connection_permission VALUES(?, ?, 'READ')";
            $stmt = $pdo->prepare($sql);
            $stmt->bindValue(1, $entity_id, PDO::PARAM_INT);
            $stmt->bindValue(2, $connection_id, PDO::PARAM_INT);
            $stmt->execute();
        }

    } catch (PDOException $e) {
        $log->err("Failed to udpate guacamole permission: ".$e->getMessage());
        exit(1);
    }
}

