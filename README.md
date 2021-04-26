# Secioss PrivilegedID
Secioss PrivilegedID は、オープンソースの特権ID管理ソフトウェアで、Linuxサーバー、Windowsサーバー、データベースサーバーへの特権IDによるアクセスの制御を行います。  
Windowsサーバーに対するアクセス制御には、Apache Guacamoleを使用しています。

## 概要
Secioss PrivilegedID では以下の機能を提供します。

* SSH/RDP/データベースへのアクセス制御  
  ユーザーに対して時間帯を指定して特権IDによるサーバーへのアクセスを許可します。
* 特権IDの操作記録  
  ユーザーがサーバーで実施した操作を記録します。SSH、データベースの場合は操作したコマンドを、RDBの場合は操作した動画を記録します。
* 特権IDの定期パスワード変更  
  Linuxサーバー、Windowsサーバーの特権IDのパスワードやSSHキーを定期的にランダムな値に変更します。
* ワークフロー連携  
  特権ID APIから特権IDの設定を行うことができます。ワークフローからAPI経由でユーザーに対して特権IDのアクセスを許可することができます。
* SAML認証  
  ユーザーの認証にはSAML認証を使用します。SAML IdPで多要素認証を行うことで、特権IDの認証を強化することができます。

## 動作環境
* OS：CentOS7
* ミドルウェア：httpd、Shibboleth SP、Guacamole

## インストール
### MariaDB セットアップ
Secioss PrivilegedID にはmysql/Mariadb が必要です。
以下のコマンドでインストールしてください。

`# yum install mariadb-server`

文字コードの設定を行います。  
/etc/my.cnf を開き [mysqld] セクションに以下の記述を追加して下さい。

    default-storage-engine=InnoDB
    character-set-server=utf8

再起動を行って下さい。

`# systemctl start mariadb`

### 管理コンソール
管理コンソールとして、LISM( https://github.com/SeciossOpenSource/LISM )をインストールして下さい。  
LISMは、Secioss PrivilegedIDと別サーバーにインストールしても構いません。  
LISMのセットアップツール(setup.sh)を起動時に、mariadbの初期設定、接続確認メニューを行って下さい。

* DBサーバの初期設定
* DBサーバへの接続設定

インストール後、SeciossLISM 配下に存在している lism-setup.sh を実行して下さい。

`# ./lism-setup.sh`

また、LISMをSecioss PrivilegedIDと別サーバーにインストールしている場合は、/opt/secioss/etc/auth_tkt.confをSecioss PrivilegeIDサーバー上にコピーして下さい。

### Secioss PrivilegedID
以下のパッケージをインストールして下さい。

`# yum install -y epel-release`

`# yum install -y php php-mbstring php-xml php-pear php-pear-Log php-pecl-uuid php-pecl-ssh2 mariadb postgresql npm sshpass memcached`

GatewayServer配下のファイルを配置します。

`# ./gateway-setup.sh`

設定ファイル/opt/secioss-gateway/www/conf/config.iniに以下の設定を行って下さい。
* ldap_uri: LISMのLDAPサーバーのURI
* ldap_binddn: LISMのLDAPサーバーに接続するユーザーのDN
* ldap_bindpw: LISMのLDAPサーバーに接続するパスワード
* ldap_basedn: LISMのLDAPサーバーのベースDN

Ratchetをインストールします。  
インストールは一般ユーザーで実行して下さい。

`$ cp /opt/secioss-gateway/www/server/composer.json .`

`$ composer install`

`$ sudo cp -r vendor /opt/secioss-gateway/www/server/`

xtermをインストールします。

`# cd /opt/secioss-gatway/www/pidgw`

`# npm install xterm`

/etc/php.iniのmbstring.internal_encodingを"UTF-8"に設定して下さい。

`mbstring.internal_encoding = UTF-8`

Secioss PrivilegedIDのデーモンを起動します。

`# systemctl start httpd`

`# systemctl start memcached`

`# php /opt/secioss-gateway/www/server/bin/websocket.php &`

### Guacamole サーバー構築
Windowsサーバーへのリモートアクセスに使用するGuacamoleサーバーをインストールして下さい。
よろしければ弊社導入記事を参考にして下さい。

[働き方改革の一助に、「Apache Guacamole」でリモートデスクトップ](https://www.secioss.co.jp/%E5%83%8D%E3%81%8D%E6%96%B9%E6%94%B9%E9%9D%A9%E3%81%AE%E4%B8%80%E5%8A%A9%E3%81%AB%E3%80%81%E3%80%8Capache-guacamole%E3%80%8D%E3%81%A7%E3%83%AA%E3%83%A2%E3%83%BC%E3%83%88%E3%83%87%E3%82%B9%E3%82%AF/)

Guacamoleサーバーの設定はデータベースで管理し、認証はShibboleth SPで行うので、以下のエクステンションのjarファイルを/etc/guacamole/extensionsに配置して下さい。

* [guacamole-auth-jdbc](http://guacamole.apache.org/releases/)
* [guacamole-auth-header](http://guacamole.apache.org/releases/)

guacamole-auth-jdbc-x.x.x.tar.gzを展開したguacamole-auth-jdbc-x.x.x/mysql/schema内のデータをデータベースに登録して下さい。

`# cat schema/*.sql | mysql -u root -p guacamole_db`

また、MySQL JDBCドライバを https://dev.mysql.com/downloads/connector/j/ から取得してインストールして下さい。

最後に/etc/guacamole/guacamole.propertiesに以下の設定を追加してから、Guacamoleのデーモンの再起動を行って下さい。

    mysql-hostname: <DBサーバーのホスト名>
    mysql-port: 3306
    mysql-database: guacamole_db
    mysql-username: <DBサーバーに接続するユーザー>
    mysql-password: <DBサーバーに接続するパスワード>
    mysql-auto-create-accounts: true
    http-auth-hader: Proxy-User

### Shibboleth SP
Secioss PrivilegedIDは、認証をSAMLで行うので、Shibboleth SPをインストールします。SAML IdPは別途用意して下さい。  
Shibboleth SPのインストール方法については、こちら（[Shibboleth SPを使ってSAMLに対応したサイトを作ろう](https://www.secioss.co.jp/shibboleth-sp%e3%82%92%e4%bd%bf%e3%81%a3%e3%81%a6saml%e3%81%ab%e5%af%be%e5%bf%9c%e3%81%97%e3%81%9f%e3%82%b5%e3%82%a4%e3%83%88%e3%82%92%e4%bd%9c%e3%82%8d%e3%81%86/)）を参考にして下さい。  
SAMLレスポンスのName IDで、LISMのユーザーIDを渡すように設定して下さい。

/etc/httpd/conf.d/shibd.confに以下の設定を追加して、httpdを再起動して下さい。

    <Location /pidgw>
      AuthType shibboleth
      ShibRequestSetting requireSession 1
      require shib-session
    </Location>
    
    <Location /guacamole>
      AuthType shibboleth
      ShibRequestSetting requireSession 1
      require shib-session
    </Location>

## 使用方法：特権IDリモートアクセス
ユーザーにリモートアクセスを行わせるための設定を行います。

### リモートアクセス先の接続情報設定
リモートアクセス先の接続情報を設定します。

1. ゲートウェイサーバー 設定
特権ID管理＞ゲートウェイサーバーより登録を行います。
2. ターゲット設定
特権ID管理＞ターゲットより登録を行います。

### 特権ID設定
リモートアクセス先のアカウント設定を行います。  
特権ID管理＞特権IDより登録を行います。
リモートアクセスの取消契機には、以下があります。  
* 期間指定：指定した期間内のみアクセスが可能です。
* 無期限：常時アクセスが可能です。

### 特権ID利用設定
リモートアクセスの設定を通常ユーザーに利用させるための設定を行います。  
ユーザー＞一覧 より対象ユーザーを選択し、特権IDタブから設定をして下さい。

### Guacamole 設定反映
設定した特権IDの情報をGuacamoleに反映するため、Secioss PrivilegedIDサーバー上で、以下の設定をcronで1時間に1回実行するように設定して下さい。

    0 * * * * root php /opt/secioss-gateway/task/guacamole_remote_sync.php

## ユーザーのアクセス
ユーザーは以下のURLからリモートアクセスが可能です。  
認証はSAMLで行うので、IdPへのログイン後、リモートアクセスすることができます。

* SSH/データベース: https://<Secioss PrivilegedIDのホスト名>/pidgw/auth.php?id=<特権IDのログイン>/<ターゲットID>
* RDP: https://<Secioss PrivilegedIDのホスト名>/guacamole/

## 使用方法：定期パスワード変更

リモートアクセス先の特権アカウントに対して定期的なパスワード変更を実施します。  
前提として **特権IDリモートアクセスの設定を行っていること** とし、以下の設定を行います。

### パスワード変更条件設定

特権IDタブ管理＞設定 よりパスワード変更を行いたい接続先とパスワード変更周期を設定して下さい。

### パスワード変更

LISMサーバー上で、cronで1日1回以下のスクリプトを実行するように設定して下さい。

    0 0 * * * root/opt/secioss/sbin/privilegedidpwdsync

Secioss PrivilegedIDサーバー上で、上記のスクリプト実行後に1日1回以下のスクリプトが実行されるように設定して下さい。

    30 0 * * * root/opt/secioss-gateway/task/privilegedid_remote_sync.php

## 特権ID API
### 認証
認証APIでセッションIDを取得して、特権ID付与APIのリクエスト時に送信して下さい。
#### リクエスト
|パラメータ|必須|説明|
|---|---|---|
|action_login|〇|true|
|id|〇|LISMの管理者のユーザーID|
|password|〇|パスワード|

#### レスポンス
    <response>
      <code>エラーコード</code>
      <sessid>セッションID</sessid>
    </response>
エラーコードは0が成功で、それ以外の値はエラーです。

### 特権ID付与
ユーザーに特権IDによるアクセスを許可します。ユーザーには複数の特権IDを付与することができます。  
#### リクエスト
|パラメータ|必須|説明|
|---|---|---|
|action_user_assignPrivilegedId|〇|true|
|sessid|〇|セッションID|
|id|〇|特権IDを付与するユーザーID|
|privilegedid[]|〇|特権ID|
|assignedservice[]|〇|対象サーバーのターゲットID|
|privilegetype[]|〇|取消契機（infinite：無期限、time_limiteation：期間指定）|
|startdate[]||アクセス開始日時（yyyy/mm/dd HH:MM:SS）|
|expirationdate[]||アクセス終了日時（yyyy/mm/dd HH:MM:SS）|

特権IDを複数付与する場合、privielgedid、assigndservice、privilgetype、startdate、expireationdateを配列で渡して下さい。

#### レスポンス
    <response>
      <code>エラーコード</code>
      <message>メッセージ</message>
    </response>
エラーコードは0が成功で、それ以外の値はエラーです。
