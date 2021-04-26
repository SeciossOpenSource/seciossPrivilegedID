#!/bin/sh

# PrivilegedId enable
file='/usr/share/seciossadmin/etc/secioss-ini.php'
sed -i -r -e '/\/\*\s*Privileged\sID/,/\*\// s/(\/\*\s*Privileged\sID|\*\/)//g' $file

# copy remote id sync files.
cp -pf ./script/privilegedidpwdsync /opt/secioss/sbin/
cp -pf ./script/privilegedidpwdsync.conf /opt/secioss/etc/
# permission
chmod +x /opt/secioss/sbin/privilegedidpwdsync
chmod +x /opt/secioss/sbin/export_privilegedid_remote.pl

echo "設定が完了しました"
