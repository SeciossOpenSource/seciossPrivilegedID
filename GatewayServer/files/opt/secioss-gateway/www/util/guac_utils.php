<?php

require_once 'PEAR.php';

class GuacUtils
{
    /**
     * Gatewayサーバーとして必須なパラメーター一覧
     *
     * @var array
     */
    private static $need_param_list = [
        'guac.protocol',
        'guac.hostname',
        'guac.port',
    ];

    /**
     * signatureを作成する為のメッセージ一覧
     * この順番でメッセージ文字列を作成する必要があります。
     *
     * @var array
     */
    private static $guac_signature_keys = [
        'guac.protocol',
        'guac.hostname',
        'guac.port',
        'guac.username',
        'guac.password',
    ];

    /**
     * レスポンスを返却する
     *
     * @param [int] $response_code
     *
     * @return void
     */
    public function response($response_code)
    {
        if ($response_code == '200') {
            return;
        }
        // Errorだけ捕捉する
        switch ($response_code) {
            case '400':
                // Bad Request.
                $message = 'Bad Request';
                break;
            case '401':
                // Unauthorixed.
                $message = 'Unauthorixed';
                break;
            case '403':
                // Forbidden
                $message = 'Forbidden';
                break;
            case '404':
                // Not Found
                $message = 'Not Found';
                break;
            default:
                // Internal Server Error
                $message = 'Internal Server Error';
                break;
        }
        // レスポンス
        http_response_code($response_code);
        header("HTTP/1.0 $response_code $message");
        $error_page = file_get_contents(dirname(__FILE__)."/../error/$response_code.html");
        echo $error_page;
        exit;
    }

    /**
     * コンフィグファイル取得
     *
     * @return [array] $config
     */
    public function get_conf()
    {
        $conf = parse_ini_file(dirname(__FILE__).'/../conf/config.ini', true);
        if (empty($conf)) {
            throw new Exception("Can't read config.ini");
        }

        if (!isset($conf['gateway']) ||
            !isset($conf['gateway']['access_url']) ||
            !isset($conf['gateway']['api_url']) ||
            !isset($conf['gateway']['secret'])
            ) {
            throw new Exception('Failed to configration file.');
        }

        return $conf;
    }

    /**
     * 必須パラメーター存在チェック
     * すべての必須パラメーター存在が存在すれば、trueを返します。
     *
     * @param [array] $need_param_list
     * @param [array] $params
     *
     * @return [bool] true|false
     */
    public function validate_param($params)
    {
        if ($this->array_multi_key_exists(self::$need_param_list, $params)) {
            return true;
        }
        return false;
    }

    /**
     * キー存在チェック
     * (複数キーワードで多次元配列をチェックします)
     * 存在すれば、trueを返します。
     *
     * @param array $keywords
     * @param array $target_array
     *
     * @return true|false
     */
    public function array_multi_key_exists($keywords, $target_array)
    {
        foreach ($keywords as $keyword) {
            if ($this->array_key_exists_recursive($keyword, $target_array)) {
                return true;
            }
        }
        return false;
    }

    /**
     * キー存在チェック
     * (単一キーワードで多次元配列をチェックします)
     * 存在すれば、trueを返します。
     *
     * @param string $keyword
     * @param array  $target_array
     *
     * @return true|false
     */
    public function array_key_exists_recursive($keyword, $target_array)
    {
        if (array_key_exists($keyword, $target_array)) {
            return true;
        }

        // 配列があれば、その中身を再帰的に走査
        foreach ($target_array as $child) {
            if (is_array($child)
                && $this->array_key_exists_recursive($keyword, $child)
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * SAML Responseから、guac.xxxパラメーターを取得
     *
     * @param [array] $saml_attr
     *
     * @return array guac_params
     */
    public function get_guac_params($saml_attr)
    {
        if (!array_key_exists('guac', $saml_attr)) {
            return false;
        }
        $json = base64_decode($saml_attr['guac'][0]);
        return json_decode($json, true);
    }

    /**
     * UUID形式で接続IDを生成する
     *
     * @return connect_id
     */
    public function get_connect_id()
    {
        return $connect_id = uuid_create(UUID_TYPE_RANDOM);
    }

    /**
     * 接続IDから、URLで指定するClientへの接続文字列を生成する
     * https://host/gc/#/client/<client_id>
     *
     * @param string $connect_id
     *
     * @return client_id|null
     */
    public function get_client_id($connect_id)
    {
        if (isset($connect_id)) {
            return base64_encode($connect_id."\0".'c'."\0".'hmac');
        }
        return null;
    }

    /**
     * signatureを作成する為のメッセージを作成する
     * $message = $timestamp . $protocol . $ip_address . $port . $username . $passwd
     *
     * @param [string] $timestamp
     * @param [array]  $guac_params
     * @param [string] $username
     * @param mixed    $privatekey
     *
     * @return signature|null
     */
    public function get_message($timestamp, $guac_params, $privatekey)
    {
        $message = $timestamp;

        foreach (self::$guac_signature_keys as $key) {
            if (isset($guac_params[$key])) {
                $message .= $guac_params[$key];
            }
        }
        return $message;
    }

    /**
     * HMAC拡張プラグインに必要な、signatureを生成する
     * $message = $timestamp . $protocol . $ip_address . $port . $username . $passwd
     *
     * @param [string] $algo
     * @param [string] $timestamp
     * @param [array]  $message
     * @param [string] $secret
     *
     * @return signature|null
     */
    public function get_signature($algo, $message, $secret)
    {
        if (isset($message)) {
            return base64_encode(hash_hmac('sha256', $message, $secret, true));
        }
        return null;
    }

    /**
     * ミリ秒を含むUnixタイムスタンプを数値で取得
     *
     * @return int
     */
    public function get_timestamp()
    {
        return ceil(microtime(true) * 1000);
    }

    /**
     * 暗号化されて送られてくるseciossEncryptedPasswordを複合化する
     *
     * @param [string] $string
     * @param [string] $privatekey
     * @param mixed    $server_privatekey
     *
     * @return string
     */
    public function decrypt($string, $server_privatekey)
    {
        if ($server_privatekey) {
            $string = base64_decode($string);
            $server_privatekey = openssl_get_privatekey('file:///opt/secioss-gateway/www/simplesamlphp/cert/'.$server_privatekey);
            if (openssl_private_decrypt($string, $decrypted, $server_privatekey, OPENSSL_PKCS1_OAEP_PADDING)) {
                return $decrypted;
            }
        }
        return $string;
    }

    /**
     * ログインに使用する秘密鍵を保存する
     *
     * @param [string] $login_privatekey
     * @param [string] $server_privatekey
     * @param mixed    $source
     * @param mixed    $prvkey
     *
     * @return bool
     */

    /**
     * リモートサーバーログインの秘密鍵を取得
     *
     * @param [type] $source
     * @param [type] $prvkey
     *
     * @return string
     */
    public function get_private_key($source, $prvkey)
    {
        $source = base64_decode($source);
        $key = openssl_get_privatekey('file:///opt/secioss-gateway/www/simplesamlphp/cert/'.$prvkey);
        $bits = self::ssl_getbits($key);
        $decrypted = '';
        $cursor = 0;
        $blocksize = $bits / 8;

        while ($data = substr($source, $cursor, $blocksize)) {
            if (!openssl_private_decrypt($data, $blockdata, $key, OPENSSL_PKCS1_OAEP_PADDING)) {
                return false;
            }
            $decrypted .= $blockdata;
            $cursor += $blocksize;
        }
        return $decrypted;
    }

    /**
     * 鍵のビット長を取得
     *
     * @param [type] $pem
     *
     * @return void
     */
    public function ssl_getbits($pem)
    {
        $key = openssl_pkey_get_private($pem);
        if (is_resource($key)) {
            $keyinfo = (object) openssl_pkey_get_details($key);
            return $keyinfo->bits;
        }

        return false;
    }

    /**
     * phpからコマンドを実行する
     *
     * @param mixed $cmd
     */
    public function secioss_exec($cmd, &$out, &$ret)
    {
        $ret = -1;
        $out = [];
        $process = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '~/', []);
        if (is_resource($process)) {
            for ($line = fgets($pipes[1]); $line != false; $line = fgets($pipes[1])) {
                if (trim($line)) {
                    array_push($out, $line);
                }
            }
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $ret = proc_close($process);
        }
    }
}
