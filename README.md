# Secioss PrivilegedID
Secioss PrivilegedID は、オープンソースの特権ID管理ソフトウェアで、Linuxサーバー、Windowsサーバー、データベースサーバーへの特権IDによるアクセスの制御を行います。
Windowsサーバーに対するアクセス制御には、Apache Guacamoleを使用しています。

## 概要
Secioss PrivilegedID では以下の機能を提供します。

* SSH/RDP/データベースへのアクセス制御
* 特権IDのサーバーでの操作記録
* Linuxサーバー、Windowsサーバーの特権IDに対するパスワードローテーション


## 動作環境
* OS：CentOS7
* ミドルウェア：httpd、Guacamole

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

### Secioss PrivilegedID
以下のパッケージをインストールして下さい。

`# yum install -y epel-release`

`# yum install -y php php-mbstring php-xml php-pear php-pear-Log php-pecl-uuid php-pecl-ssh2`

GatewayServer配下のファイルを配置します。

`# ./gateway-setup.sh`

### Guacamole サーバー構築

Windowsサーバーへのリモートアクセスに使用するGuacamoleサーバーをインストールして下さい。  
よろしければ弊社導入記事を参考にして下さい。

[働き方改革の一助に、「Apache Guacamole」でリモートデスクトップ](https://www.secioss.co.jp/%E5%83%8D%E3%81%8D%E6%96%B9%E6%94%B9%E9%9D%A9%E3%81%AE%E4%B8%80%E5%8A%A9%E3%81%AB%E3%80%81%E3%80%8Capache-guacamole%E3%80%8D%E3%81%A7%E3%83%AA%E3%83%A2%E3%83%BC%E3%83%88%E3%83%87%E3%82%B9%E3%82%AF/)

Guacamoleサーバーの設定はデータベースで管理し、認証はShibboleth SPで行うので、以下のエクステンションを/etc/guacamole/extensionsに配置して下さい。

* [guacamole-auth-jdbc-1.3.0.tar.gz](https://apache.org/dyn/closer.cgi?action=download&filename=guacamole/1.3.0/binary/guacamole-auth-jdbc-1.3.0.tar.gz)
* [guacamole-auth-header-1.2.0.tar.gz](https://apache.org/dyn/closer.cgi?action=download&filename=guacamole/1.3.0/binary/guacamole-auth-header-1.2.0.tar.gz)

また、MySQL JDBCドライバを https://dev.mysql.com/downloads/connector/j/ から取得してインストールして下さい。

### 管理コンソール
管理コンソールとして、LISM( https://github.com/SeciossOpenSource/LISM )をインストールして下さい。
LISMは、Secioss PrivilegedIDと別サーバーにインストールしても構いません。
LISMのセットアップツール(setup.sh)を起動時に、mariadbの初期設定、接続確認メニューを行って下さい。

* DBサーバの初期設定
* DBサーバへの接続設定

インストール後、SeciossLISM 配下に存在している lism-setup.sh を実行して下さい。

`# ./lism-setup.sh`

lism-setup.shを実行すると、公開鍵証明書が作成されるので、Secioss PrivilegedIDサーバーにに配置して下さい。。

`# scp LISMサーバー:/opt/secioss/etc/gateway_public.pem /opt/secioss-gateway/www/simplesamlphp/cert/PublicKey-idp.pem`

## 使用方法：特権IDリモートアクセス

ユーザーにリモートアクセスを行わせるための設定を

### リモートアクセス先の接続情報設定

リモートアクセス先の接続情報を設定します。

1. ゲートウェイサーバー 設定
特権ID管理＞ゲートウェイサーバーより登録を行います。
1. ターゲット設定
特権ID管理＞ターゲットより登録を行います。
### 特権ID設定

リモートアクセス先のアカウント設定を行います。  
特権ID管理＞特権IDより登録を行います。
### 特権ID利用設定

リモートアクセスの設定を通常ユーザーに利用させるための設定を行います。  
ユーザー＞一覧 より対象ユーザーを選択し、特権IDタブから設定をして下さい。

### Guacamole 設定反映

Secioss PrivilegedIDサーバー上で、以下のスクリプトを実行します。

`# /opt/secioss-gateway/task/guacamole_remote_sync.pl`

## 使用方法：定期パスワード変更

リモートアクセス先の特権アカウントに対して定期的なパスワード変更を実施します。  
前提として **特権IDリモートアクセスの設定を行っていること** とし、以下の設定を行います。

### パスワード変更条件設定

特権IDタブ管理＞設定 よりパスワード変更を行いたい接続先とパスワード変更周期を設定して下さい。

### パスワード変更

LISMサーバーにて以下のスクリプトを実行します。

`# /opt/secioss/sbin/privilegedidpwdsync`

スクリプト実行後、Guacamoleサーバーに存在しているスクリプトを実行します。

`# php /opt/secioss-gateway/task/privilegedid_remote_sync.php`
