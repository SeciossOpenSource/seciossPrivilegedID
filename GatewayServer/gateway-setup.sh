#!/bin/bash

# copy remote id sync files.
\cp -rf ./files/opt /
cp ./httpd/* /etc/httpd/conf.d/
chown -R apache.apache /opt/secioss-gateway/www/pidgw

# Create Certificate.
certdir='/opt/secioss-gateway/www/simplesamlphp/cert'
mkdir -p $certdir

echo "証明書作成用の情報を入力して下さい"

country=
while [ -z "$country" ]; do
    printf "Country: "
    read country leftover
done

state=
while [ -z "$state" ]; do
    printf "State: "
    read state leftover
done

locality=
while [ -z "$locality" ]; do
    printf "Locality: "
    read locality leftover
done

organizational_name=
while [ -z "$organizational_name" ]; do
    printf "Organizational Name: "
    read organizational_name leftover
done

organizational_unit=
while [ -z "$organizational_unit" ]; do
    printf "Organizational Unit: "
    read organizational_unit leftover
done

common_name=
while [ -z "$common_name" ]; do
    printf "Common Name: "
    read common_name leftover
done

openssl genrsa -out ${certdir}/PrivateKey.pem 2048 2>&1
openssl req -new -x509 -key ${certdir}/PrivateKey.pem -out ${certdir}/PublicKey.pem -days 365 -subj "/C=${country}/ST=${state}/L=${locality}/O=${organizational_name}/OU=${organizational_unit}/CN=${common_name}" -sha256 2>&1

# write Config
echo "Gateway サーバーのFQDNを入力して下さい"

host=
while [ -z "$host" ]; do
    printf "host(`hostname`): "
    read host leftover
done

echo "LISMの認証情報を入力して下さい"

url=
while [ -z "$url" ]; do
    printf "URL: https://"
    read url leftover
done

user=
while [ -z "$user" ]; do
    printf "ユーザー: "
    read user leftover
done

passwd=
while [ -z "$passwd" ]; do
    printf "パスワード: "
    read passwd leftover
done

echo "GatewayID を設定します。一意なIDを入力して下さい"

gateway_id=
while [ -z "$gateway_id" ]; do
    printf "GatewayID: "
    read gateway_id leftover
done

file='/opt/secioss-gateway/www/conf/config.ini'
sed -i -r -e "s/^(host\s*=\s*\")[^\"]*(\")/\1${host}\2/g" $file
sed -i -r -e "s/^(url\s*=\s*\")[^\"]*(\")/\1https:\/\/${url}\2/g" $file
sed -i -r -e "s/^(user\s*=\s*\")[^\"]*(\")/\1${user}\2/g" $file
sed -i -r -e "s/^(passwd\s*=\s*\")[^\"]*(\")/\1${passwd}\2/g" $file
sed -i -r -e "s/^(gateway_id\s*=\s*\")[^\"]*(\")/\1${gateway_id}\2/g" $file
sed -i -r -e "s/^(privatekey\s*=\s*\")[^\"]*(\")/\1PrivateKey.pem\2/g" $file
sed -i -r -e "s/^(publickey\s*=\s*\")[^\"]*(\")/\1PublicKey.pem\2/g" $file

echo "設定が完了しました"
