# secioss PrivilegedID
Secioss PrivilegedID は、LISM(LDAP Identity Synchronization Manager) に特権ID管理機能を拡張するモジュールです。

## 概要
Secioss PrivilegedID では以下の機能を提供します。

* Guacamoleを介したSSH/RDPのリモートアクセス
* 同一ネットワーク内に存在するマシンの特権アカウントのパスワード変更


## 動作環境
* OS：CentOS7
* ミドルウェア：LISM、Guacamole

## インストール
### 事前準備
LISM を導入してください。

### LISM LDAPデータ修正
既存のデータに対して一部変更を行います。

ldifフォルダに入っている modify.ldif を適用してください。
suffixのdc=example,dc=comは、OpenLDAPの設定に合わせて変更して下さい。

Suffix 変更
`# sed -i -e s/dc=example,dc=com/対象のベースDN/g modify.ldif`

LDAP適応
`# ldapmodify -Y EXTERNAL -H ldapi:// -f modify.ldif`

設定が完了したら、OpenLDAPを再起動します。

`systemctl restart slapd`

### LISM モジュール セットアップシェル実行

SeciossLISM 配下に存在している setup.sh を実行して下さい。

`# ./lism-setup.sh`

### Guacamole サーバー構築

LISMとは別のサーバーにリモートアクセスに使用するGuacamoleサーバーを構築して下さい。
よろしければ弊社導入記事を参考にして下さい。

[働き方改革の一助に、「Apache Guacamole」でリモートデスクトップ](https://www.secioss.co.jp/%E5%83%8D%E3%81%8D%E6%96%B9%E6%94%B9%E9%9D%A9%E3%81%AE%E4%B8%80%E5%8A%A9%E3%81%AB%E3%80%81%E3%80%8Capache-guacamole%E3%80%8D%E3%81%A7%E3%83%AA%E3%83%A2%E3%83%BC%E3%83%88%E3%83%87%E3%82%B9%E3%82%AF/)

### Guacamole サーバー ID同期モジュール セットアップ

以下のパッケージをインストールして下さい。

`# yum install -y epel-release`
`# yum install -y php php-mbstring php-xml php-pear php-pear-Log php-pecl-uuid`

GatewayServer配下のファイルを配置します。

`# ./gateway-setup.sh`

LISMセットアップシェルで作成した公開鍵証明書をGatewayServerに配置します。

`# scp ユーザー@LISMサーバー:/opt/secioss/etc/gateway_public.pem /opt/secioss-gateway/www/simplesamlphp/cert/PublicKey-idp.pem`

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

LISMサーバーにて以下のスクリプトを実行します。

`# /opt/secioss/sbin/export_privilegedid_remote.pl`

その後以下のパスに Guacamole接続用のプロファイル user-mapping.xml が出力されますので、Guacamole サーバーへ転送します。

`/opt/secioss/etc/user-mapping.xml`

Guacamole 配置先

`/etc/guacamole/user-mapping.xml`

転送後は、Guacamoleやtomcatの再起動を行い、
利用を許可したユーザーの情報でログインします。

その後利用したい接続先を選択することでリモートアクセスが可能です。

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
