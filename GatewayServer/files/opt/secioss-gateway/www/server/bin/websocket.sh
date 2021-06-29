#!/bin/sh

LOGFILE=/var/log/pidgw

case "$1" in
    start)
        for port in `sed -n "s/^  BalancerMember .*:\([0-9]*\) route=.*$/\1/p" /etc/httpd/conf.d/auth.conf`; do
            php /opt/secioss-gateway/www/server/bin/websocket.php $port >& ${LOGFILE}_$port.log &
        done
        ;;
    stop)
        for pid in `ps -ef | grep websocket.php | grep -v grep | awk '{print $2}'`; do
            kill $pid
        done
        ;;
esac
