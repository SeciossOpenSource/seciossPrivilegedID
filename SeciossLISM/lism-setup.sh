#!/bin/sh

# PrivilegedId enable
file='/usr/share/seciossadmin/etc/secioss-ini.php'
sed -i -r -e '/\/\*\s*Privileged\sID/,/\*\// s/(\/\*\s*Privileged\sID|\*\/)//g' $file

# copy remote id sync files.
cp -pf ./script/privilegedidpwdsync /opt/secioss/sbin/
cp -pf ./script/privilegedidpwdsync.conf /opt/secioss/etc/
cp -pf ./script/export_privilegedid_remote.pl /opt/secioss/sbin/
# permission
chmod +x /opt/secioss/sbin/privilegedidpwdsync
chmod +x /opt/secioss/sbin/export_privilegedid_remote.pl

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

echo "設定が完了しました"
