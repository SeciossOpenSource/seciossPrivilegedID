<?php
/*
 *
 *  Servidorsocket.php
 *
 *  @author     Kaoru Sekiguchi <sekiguchi.kaoru@secioss.co.jp>
 *  @copyright  2020 SECIOSS, INC.
 *
*/

namespace MyApp;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class Servidorsocket implements MessageComponentInterface
{
    const COLS = 480;
    const ROWS = 144;
    protected $clients;
    protected $connection = [];
    protected $shell = [];
    protected $protocol = [];
    protected $idConnection = [];
    protected $conf;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();

        $conf = parse_ini_file(dirname(__DIR__).'/../conf/config.ini', true);
        $this->conf = $conf['gateway'];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection to send messages to later
        $this->clients->attach($conn);
        $this->connection[$conn->resourceId] = null;
        $this->shell[$conn->resourceId] = null;
        $this->protocol[$conn->resourceId] = null;
        $this->idConnection[$conn->resourceId] = null;
        $this->recording[$conn->resourceId] = null;
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        switch (key($data)) {
            case 'data':
                fwrite($this->shell[$from->resourceId], $data['data']['data']);
                usleep(800);
                while (($line = fgets($this->shell[$from->resourceId])) !== false) {
                    $from->send(mb_convert_encoding($line, 'UTF-8'));
                    if ($this->recording[$from->resourceId]) {
                        fwrite($this->recording[$from->resourceId], $line);
                    }
                }
                break;
            case 'auth':
                $userid = $from->Session->get('userid');
                if (!$userid) {
                    break;
                }

                $connected = false;
                $idConnection = $data['auth']['idconnection'];
                foreach ($this->idConnection as $resourceId => $idconn) {
                    if ($idConnection === $idconn) {
                        $this->connection[$from->resourceId] = $this->connection[$resourceId];
                        $this->shell[$from->resourceId] = $this->shell[$resourceId];
                        $this->protocol[$from->resourceId] = $this->protocol[$resourceId];
                        $this->idConnection[$from->resourceId] = $idConnection;

                        fwrite($this->shell[$from->resourceId], "\n");
                        usleep(800);
                        fgets($this->shell[$from->resourceId]);
                        while (($line = fgets($this->shell[$from->resourceId])) !== false) {
                            $from->send(mb_convert_encoding($line, 'UTF-8'));
                            if ($this->recording[$from->resourceId]) {
                                fwrite($this->recording[$from->resourceId], $line);
                            }
                        }
                        $connected = true;
                    }
                }

                if (!$connected) {
                    $conns = $from->Session->get('conns');
                    $protocol = $conns[$idConnection]['protocol'];
                    if (preg_match('/^sql_/', $protocol)) {
                        $rc = $this->connectSQL($idConnection, $from);
                    } else {
                        $rc = $this->connectSSH($idConnection, $from);
                    }
                    if ($rc) {
                        while (($line = fgets($this->shell[$from->resourceId])) !== false) {
                            $from->send(mb_convert_encoding($line, 'UTF-8'));
                            if ($this->recording[$from->resourceId]) {
                                fwrite($this->recording[$from->resourceId], $line);
                            }
                        }
                    } else {
                        $from->send(mb_convert_encoding('Error, can not connect to the server. Check the credentials', 'UTF-8'));
                        $from->close();
                    }
                }
                break;
            default:
                if ($this->protocol[$from->resourceId]) {
                    while (($line = fgets($this->shell[$from->resourceId])) !== false) {
                        $from->send(mb_convert_encoding($line, 'UTF-8'));
                        if ($this->recording[$from->resourceId]) {
                            fwrite($this->recording[$from->resourceId], $line);
                        }
                    }
                }
                break;
        }
    }

    public function connectSSH($idConnection, $from)
    {
        $conns = $from->Session->get('conns');
        $protocol = $conns[$idConnection]['protocol'];
        $server = $conns[$idConnection]['server'];
        $port = $conns[$idConnection]['port'];
        $user = $conns[$idConnection]['user'];
        $password = null;
        $privatekey_file = null;
        $passphrase = null;
        $publickey_file = null;
        if (isset($conns[$idConnection]['private_key'])) {
            $privatekey = $conns[$idConnection]['private_key'];
            $publickey = $conns[$idConnection]['public_key'];
            if (isset($conns[$idConnection]['passphrase'])) {
                $passphrase = $conns[$idConnection]['passphrase'];
            }
            $privatekey_file = dirname(__DIR__).'/../tmp/private-'.$idConnection;
            $publickey_file = dirname(__DIR__).'/../tmp/public-'.$idConnection;
            file_put_contents($privatekey_file, $privatekey);
            file_put_contents($publickey_file, $publickey);
            chmod($privatekey_file, 400);
        } else {
            $password = $conns[$idConnection]['password'];
        }

        if (isset($conns[$idConnection]['recording_log'])) {
            $fd = fopen($conns[$idConnection]['recording_log'].'#'.$idConnection, 'a');
            if ($fd === false) {
            } else {
                $this->recording[$from->resourceId] = $fd;
            }
        }

        $this->connection[$from->resourceId] = ssh2_connect($server, $port);
        if ($privatekey_file) {
            if ($passphrase) {
                $rc = ssh2_auth_pubkey_file($this->connection[$from->resourceId], $user, $publickey_file, $privatekey_file, $passphrase);
            } else {
                $rc = ssh2_auth_pubkey_file($this->connection[$from->resourceId], $user, $publickey_file, $privatekey_file);
            }
            unlink($privatekey_file);
            unlink($publickey_file);
        } else {
            $rc = ssh2_auth_password($this->connection[$from->resourceId], $user, $password);
        }
        if ($rc) {
            //$conn->send("Authentication Successful!\n");
            $this->shell[$from->resourceId] = ssh2_shell($this->connection[$from->resourceId], 'xterm', null, self::COLS, self::ROWS, SSH2_TERM_UNIT_CHARS);
            sleep(1);
            $this->protocol[$from->resourceId] = $protocol;
            $this->idConnection[$from->resourceId] = $idConnection;
            return true;
        } else {
            return false;
        }
    }

    public function connectSQL($idConnection, $from)
    {
        $conns = $from->Session->get('conns');
        $protocol = $conns[$idConnection]['protocol'];
        $server = $conns[$idConnection]['server'];
        $port = $conns[$idConnection]['port'];
        $user = $conns[$idConnection]['user'];
        $password = $conns[$idConnection]['password'];
        $database = isset($conns[$idConnection]['database']) ? $conns[$idConnection]['database'] : null;

        if (isset($conns[$idConnection]['recording_log'])) {
            $fd = fopen($conns[$idConnection]['recording_log'].'#'.$idConnection, 'a');
            if ($fd === false) {
            } else {
                $this->recording[$from->resourceId] = $fd;
            }
        }

        $this->connection[$from->resourceId] = ssh2_connect('localhost', 22);
        $rc = ssh2_auth_pubkey_file($this->connection[$from->resourceId], $this->conf['local_user'], $this->conf['local_publickey'], $this->conf['local_privatekey']);
        if ($rc) {
            //$conn->send("Authentication Successful!\n");
            $this->shell[$from->resourceId] = ssh2_shell($this->connection[$from->resourceId], 'xterm', null, self::COLS, self::ROWS, SSH2_TERM_UNIT_CHARS);
            sleep(1);
            $this->protocol[$from->resourceId] = $protocol;
            $this->idConnection[$from->resourceId] = $idConnection;

            while (fgets($this->shell[$from->resourceId]) !== false) {
            }
            switch ($protocol) {
                case 'sql_oracle':
                    $cmd = "sqlplus64 $user/$password@$server".($port ? ":$port" : '').($database ? "/$database" : '')."; exit\n";
                    break;
                case 'sql_postgres':
                    $cmd = "psql --host=$server --username=$user --password=$password".($port ? "--port=$port " : '').($database ? " --dbname=$database" : '')."; exit\n";
                    break;
                default:
                    $cmd = "mysql --host=$server --user=$user --password=$password".($port ? " --port=$port " : '').($database ? " $database" : '')."; exit\n";
            }
            fwrite($this->shell[$from->resourceId], $cmd);
            usleep(1500);
            fgets($this->shell[$from->resourceId]);
            return true;
        } else {
            return false;
        }
    }

    public function onClose(ConnectionInterface $conn)
    {
        // The connection is closed, remove it, as we can no longer send it messages

        if (preg_match('/^sql_/', $this->protocol[$conn->resourceId])) {
        }
        $this->protocol[$conn->resourceId] = null;
        if ($this->recording[$conn->resourceId]) {
            fclose($this->recording[$conn->resourceId]);
        }
        $this->clients->detach($conn);
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        $conn->close();
    }
}
