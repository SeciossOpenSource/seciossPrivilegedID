<?php

//--------------------------
// HTTPステータスコード
//--------------------------
// 成功
define('OK_SUCESS', 200);                     // 成功
define('OK_CREATED', 201);                    // 新しいリソースを作成した。POST、PUT
define('OK_ACCEPTED', 202);                   //リクエストを受け付けた。同期的に処理できない時
define('OK_NO_CONTENT', 204);                 // DELETEなどでレスポンスボディが不要な時
// 失敗
define('E_BAD_REQUEST', 400);                 // パラメーターエラー
define('E_NOT_FOUND', 404);                   // リソースが存在しない
define('E_CONFLICT', 409);                    // リソースが競合している。ユニークなキーが既存のリソースと衝突した場合等
define('E_INTERNAL_ERROR', 500);              // サーバー側の問題によるエラー
define('E_SERVICE_UNAVAILABLE', 503);        // 一時的にサービス提供ができない場合。（メンテナンス等）
// コマンド実行 オプション
if (file_exists('/usr/bin/timeout')) {
    define('TIMEOUT_CMD', '/usr/bin/timeout');
} else {
    define('TIMEOUT_CMD', '/bin/sh /usr/share/doc/bash-3.2/scripts/timeout');
}
define('TIMEOUT', 55);

require_once 'Log.php';
require_once dirname(__FILE__).'/../www/util/guac_utils.php';

// Time Zone設定
if (!ini_get('date.timezone')) {
    ini_set('date.timezone', 'Asia/Tokyo');
}
// Log
$logid = 'PRIVILEGEDID_REMOTE_SYNC';
$log = Log::singleton('syslog', LOG_LOCAL4, $logid);

/**
 * 証明書を使った復号化を行う
 *
 * @param string $string
 * @param string $privatekey
 *
 * @return string
 */
function _decrypt($string, $privatekey)
{
    if ($privatekey) {
        $key = openssl_get_privatekey('file://'.$privatekey);
        if (openssl_private_decrypt($string, $decrypted, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
            return $decrypted;
        }
    }
    return null;
}

/**
 * 証明書を使った復号化を行う
 *
 * @param string $string
 * @param string $publickey
 *
 * @return string
 */
function _encrypt($string, $publickey)
{
    if ($publickey) {
        $key = openssl_get_publickey('file://'.$publickey);
        if (openssl_public_encrypt($string, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
            return $encrypted;
        }
    }
    return null;
}

/**
 * Send Request Provisioning API
 *
 * @param mixed $data
 * @param mixed $url
 */
function _fetchProvisioningAPI($data, $url)
{
    global $log;
    $obj = null;

    // 新しい cURL リソースを作成
    $ch = curl_init();
    // URLや他のオプションを設定
    $options = [
        CURLOPT_URL => $url.'/admin/api/',
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => false,    // debug用
        CURLOPT_TIMEOUT => 180,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
    ];
    curl_setopt_array($ch, $options);

    // API呼び出し
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    if (CURLE_OK !== $errno) {
        // curl実行エラー
        $error = curl_error($ch);
        $log->crit("An error occurred in the SeciossLink API request. errno=$errno message=$error");
        throw new Exception('Internal server error', E_INTERNAL_ERROR);
    }
    $info = curl_getinfo($ch);
    if (isset($info) && $info['http_code'] != OK_SUCESS) {
        // レスポンスパラメーターエラー
        $log->crit('An error occurred in the SeciossLink API request. errno='.$info['http_code']);
        throw new Exception('Internal server error', E_INTERNAL_ERROR);
    }
    $obj = simplexml_load_string($body);

    curl_close($ch);

    return $obj;
}

/**
 * login API
 *
 * @param [string] $id       adminUserId
 * @param [string] $tenant   tenant
 * @param [string] $password password
 * @param [string] $url
 *
 * @return string sessionid
 */
function _login($id, $tenant, $password, $url)
{
    global $log;
    // result
    $sessid = null;

    // data
    $data = [
        'action_login' => 'true',
        'id' => $id.($tenant ? '@'.$tenant : ''),
        'password' => $password,
    ];
    // logging
    $msg = 'Login request. [user = '.$id.($tenant ? '@'.$tenant : '').']';
    $log->info($msg);

    $xml = _fetchProvisioningAPI($data, $url);
    if (isset($xml) && $xml) {
        // convert php array.
        $json = json_encode($xml);
        $obj = json_decode($json, true);

        $sessid = $obj['sessid'];
    } else {
        $obj['code'] = -1;
        $obj['message'] = 'unknown error. convert xml -> php array variable.';
    }

    if (isset($obj) && isset($obj['code']) && $obj['code'] == 0) {
        $log->info('Success Login.');
    } else {
        $log->err('failed Login. [code = '.$obj['code'].', message = '.$obj['message'].']');
    }

    return $sessid;
}

function _updateSessionRecords($sessid, $gatewayid, $dir, $url)
{
    global $log;

    if (!$dir) {
        return 0;
    }

    $records = [];
    $files = glob("$dir/*");
    foreach ($files as $file) {
        $records[] = basename($file);
    }

    $data = [
        'sessid' => $sessid,
        'action_server_privilegedIdSessionRecord' => 'true',
        'gatewayid' => $gatewayid,
        'records' => $records,
    ];
    // logging
    $log->info('Session Records Update request. [gatewayid = '.$gatewayid.']');

    $xml = _fetchProvisioningAPI($data, $url);
    if (isset($xml) && $xml) {
        // convert php array.
        $json = json_encode($xml);
        $obj = json_decode($json, true);
    } else {
        $obj['code'] = -1;
        $obj['message'] = 'unknown error. convert xml -> php array variable.';
    }

    if (isset($obj) && isset($obj['code']) && $obj['code'] == 0) {
        $log->info('Session Records update succeeded.');
    } else {
        $log->err('Session Records update failed. [code = '.$obj['code'].', message = '.$obj['message'].']');
    }

    return $obj['code'];
}

/**
 * Gateway Taskdata Get API
 *
 * @param [string] $sessid    login sessionid
 * @param [string] $gatewayid gatewayid
 * @param [string] $status    0...newtask, 1...completetask
 * @param [string] $url
 *
 * @return Array<Object> TaskLists
 */
function _getTaskData($sessid, $gatewayid, $status, $url)
{
    global $log;
    // result
    $obj = [];

    // data
    $data = [
        'sessid' => $sessid,
        'action_server_privilegedIdTaskRead' => 'true',
        'gatewayid' => $gatewayid,
        'status' => $status,
    ];
    // logging
    $log->info('Gateway Taskdata Get request. [gatewayid = '.$gatewayid.']');

    $xml = _fetchProvisioningAPI($data, $url);
    if (isset($xml) && $xml) {
        // convert php array.
        $json = json_encode($xml);
        $obj = json_decode($json, true);
    } else {
        $obj['code'] = -1;
        $obj['message'] = 'unknown error. convert xml -> php array variable.';
    }

    if (isset($obj) && isset($obj['code']) && $obj['code'] == 0) {
        $log->info('Success get Gateway Taskdata.');
    } else {
        $log->err('failed get Gateway Taskdata. [code = '.$obj['code'].', message = '.$obj['message'].']');
    }

    return $obj;
}

/**
 * Gateway Taskdata Set API
 *
 * @param [string] $sessid         login sessionid
 * @param [string] $taskid
 * @param [string] $privilegeduser
 * @param [string] $gatewayid
 * @param [string] $resultcode
 * @param [string] $resultobj
 * @param [string] $type
 * @param [string] $completedate
 * @param [string] $url
 * @param mixed    $user
 *
 * @return object resultObject
 */
function _setTaskData($sessid, $taskid, $user, $gatewayid, $resultcode, $resultobj, $type, $completedate, $url)
{
    global $log;
    // result
    $obj = [];

    // data
    $data = [
        'sessid' => $sessid,
        'action_server_privilegedIdTaskUpdate' => 'true',
        'taskid' => $taskid,
        'gatewayid' => $gatewayid,
        'username' => $user,
        'resultcode' => $resultcode,
        'result' => $resultobj,
        'type' => $type,
        'completedate' => $completedate,
    ];
    // logging
    $log->info('Gateway Taskdata Set request. [taskid = '.$taskid.']');

    $xml = _fetchProvisioningAPI($data, $url);
    if (isset($xml) && $xml) {
        // convert php array.
        $json = json_encode($xml);
        $obj = json_decode($json, true);
    } else {
        $obj['code'] = -1;
        $obj['message'] = 'unknown error. convert xml -> php array variable.';
    }

    if (isset($obj) && isset($obj['code']) && $obj['code'] == 0) {
        $log->info('Success set Gateway Taskdata.');
    } else {
        $log->err('failed set Gateway Taskdata. [code = '.$obj['code'].', message = '.$obj['message'].']');
    }

    return $obj;
}

/* ****************************
 * MAIN
 * ****************************/
$log->info('Start privilegedid_remote_sync');

$certdir = '/opt/secioss-gateway/www/simplesamlphp/cert';
$options = getopt('certdir:');
if (isset($options['certdir']) && $options['certdir']) {
    $certdir = $options['certdir'];
}

$util = new GuacUtils();
$conf = $util->get_conf();
$seciosslink = $conf['seciosslink']['url'];
$tenant = $conf['seciosslink']['tenant'];
$user = $conf['seciosslink']['user'];
$pword = $conf['seciosslink']['pword'];
$key = $conf['seciosslink']['key'];
$iv = $conf['seciosslink']['iv'];
$gateway_id = $conf['gateway']['gateway_id'];
$privatekey = $certdir.'/'.$conf['gateway']['privatekey'];
$publickey = $certdir.'/PublicKey-idp'.($tenant ? "-$tenant" : '').'.pem';
$record_dir = isset($conf['gateway']['record_dir']) ? $conf['gateway']['record_dir'] : null;

if (isset($conf['seciosslink']['passwd']) && $conf['seciosslink']['passwd']) {
    $passwd = $conf['seciosslink']['passwd'];
} else {
    $text = base64_decode($pword);
    $passwd = openssl_decrypt($text, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
}

// 暗号化用証明書チェック
if (!file_exists($privatekey)) {
    $log->info("Decrypt Certirficate is not exists. ($privatekey)");
    exit(1);
}
if (!file_exists($publickey)) {
    $log->info("Encrypt Certirficate is not exists. ($publickey)");
    exit(1);
}

// ログイン
$sessionid = _login($user, $tenant, $passwd, $seciosslink);
if (!$sessionid) {
    $log->err('SeciossLink login failed.');
    exit(1);
}

if ($tenant) {
    $gateway_id = $gateway_id.'-'.$tenant;
}

_updateSessionRecords($sessionid, $gateway_id, $record_dir, $seciosslink);

// タスクリスト取得
$tasklist = _getTaskData($sessionid, $gateway_id, 0, $seciosslink);
if (!count($tasklist['entries'])) {
    // データなし
    $log->info('Taskdata is not exists');
} else {
    $datalist = $tasklist['entries'];
    // タスクが複数個ある時の中身調整
    if (!isset($datalist['entry']['taskid'])) {
        $datalist = $datalist['entry'];
    }

    foreach ($datalist as $key => $entry) {
        if ((!isset($entry['type'])) || ($entry['type'] != 'passwordsync')) {
            // パスワード同期以外のタスクはスキップ
            continue;
        }
        $taskid = $entry['taskid'];
        $username = $entry['userName'];
        $object = $entry['object'];
        $log->info("start task job [taskid = $taskid]");
        // base64 復号化
        $decoded = base64_decode($object);
        if (!$decoded) {
            $log->err('failed to base64 decode Ojbect.');
            exit(1);
        }
        // saml証明書 復号化
        $decoded = _decrypt($decoded, $privatekey);
        if (!$decoded) {
            $log->err('failed to SAML certificate decrypt Ojbect.');
            exit(1);
        }
        $decrypt = $decoded;
        // jsonテキスト から PHP object 変換
        $data = json_decode($decrypt, true);

        $targetid = $data['targetid'];
        $newpassword = $data['newpassword'];
        $protocol = $data['protocol'];
        $hostname = $data['hostname'];
        $port = $data['port'];
        $domain = isset($data['domain']) ? $data['domain'] : '';
        $accountid = $data['accountid'];
        $password = $data['password'];

        $message = '';

        if ($protocol == 'rdp') {
            // ==== RDP パターン ====
            // ホスト名がIPアドレスか確認
            if (!preg_match('/^(?:\d{1,3}\.?){4}$/', $hostname)) {
                $dnsinfo = dns_get_record($hostname);
                $hostname = $dnsinfo[0]['ip'];
            }
            $log->info('privilegedid password sync for Windows.');
            /*
             * Linux版のPowerShellは標準入力も出力されてしまい、確認したいコマンドの出力が分からないため、
             * 必要なコマンドのみテキストファイルに書き出して確認する。
             */

            $tmplog = '/tmp/'.$logid.date('YmdHis').'.log';

            $cmd = <<<'EOF'
pwsh -NoLogo -NoExit -NoProfile -NonInteractive - <<'EOS'
$ID = "
EOF;
            $cmd .= $accountid;
            $cmd .= <<<'EOF'
"
$PlainPassword = "
EOF;
            $cmd .= $password;
            $cmd .= <<<'EOF'
"
$SecurePassword = ConvertTo-SecureString –String $PlainPassword –AsPlainText -Force
$Credential = New-Object System.Management.Automation.PSCredential($ID, $SecurePassword)
New-Item -Type File 
EOF;
            $cmd .= $tmplog;
            $cmd .= <<<'EOF'

Invoke-Command 
EOF;
            $cmd .= $hostname;
            $cmd .= <<<'EOF'
 -Credential $Credential -Authentication Negotiate -ScriptBlock {net user 
EOF;
            $cmd .= "$accountid $newpassword} > $tmplog";
            $cmd .= <<<'EOF'

exit
exit
EOS
EOF;

            $util->secioss_exec($cmd, $res, $rc);
            // エラー処理

            $res = file_get_contents($tmplog);
            if (isset($res) && trim($res) == 'コマンドは正常に終了しました。') {
                $message = 'Success change password.';
                $log->info($message);
            } else {
                $message = implode($res);
                $log->err("Failed change password. [code = $rc, msg = $message]");
            }
        } elseif ($protocol == 'ssh') {
            // ==== SSH パターン ====
            $log->info('privilegedid password sync for Linux.');

            $cmd = 'sshpass -p "'.$password.'" ssh -o StrictHostKeyChecking=no '.$accountid.'@'.$hostname.' "echo '.$newpassword.' | passwd --stdin '.$accountid.'"';

            $util->secioss_exec($cmd, $res, $rc);
            // エラー処理
            if ($rc) {
                $message = 'Failed change password.';
                $errmsg = implode($res) ? implode($res) : 'unknown message';
                $log->err("Failed change password. [code = $rc, msg = $errmsg]");
            } else {
                $message = 'Success change password.';
                $log->info($message);
            }
        } else {
            $log->err('protocol type is unknown.');
            exit(1);
        }
        // 完了データ
        $resobj = ['message' => $message, 'newpassword' => $newpassword, 'targetid' => $targetid, 'accountid' => $accountid];
        // json に直す
        $resjson = json_encode($resobj);
        // Idp証明書で暗号化
        $encrypted = _encrypt($resjson, $publickey);
        if ($encrypted) {
            // base64 エンコード
            $encoded = base64_encode($encrypted);
        } else {
            $log->err('failed to certificate encrypted: complete data object.');
            exit(1);
        }

        //完了日付
        $compdate = date('Y-m-d H:i:s');
        // 完了結果 書き込み
        $res = _setTaskData($sessionid, $taskid, $username, $gateway_id, $rc, $encoded, 'passwordsync', $compdate, $seciosslink);

        if (isset($res['code']) && $res['code']) {
            $log->err('failed to execute Task Update API('.$res['code'].'). ['.$res['message'].']');
            exit(1);
        } else {
            $log->info('Task Update Success.');
        }

        $log->info("complete task job. [taskid = $taskid]");
    }
}

$log->info('End privilegedid_remote_sync');
exit(0);
