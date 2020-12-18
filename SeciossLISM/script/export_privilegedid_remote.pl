#!/usr/bin/perl
#
# PrivilegedID export for guacamole
#
# Copyright(c) 2020 SECIOSS, INC.

use strict;
use warnings;

use Config::IniFiles;
use Data::Dumper;
use File::Basename;
use Getopt::Std;
use Net::LDAP;
use XML::Simple;
use Sys::Syslog;
use PHP::Serialization qw(unserialize);
use MIME::Base64;
use JSON;
use Fcntl;


# git:SeciossLink/identitymanager/src/LISM-enterprise/lib/LISM/Utils/secioss_util.pl
require 'LISM/Utils/secioss_util.pl';

#### CONSTANTS ####
my $MYNAME = basename($0);

our $ATTR_PRIVILEGEDROLE = 'seciossprivilegerole';
our $ATTR_ENCRYPTEDPASSWORD = 'seciossencryptedpassword';
our $ATTR_LOGINID = 'seciossloginid';

### CONFIGURATION ###
my $conf_file = '/var/www/conf/config.ini';
my $conf = Config::IniFiles->new(-file => $conf_file);

#### LDAP ###
my $LDAP_HOST = _delquote($conf->val('password', 'uri'));
my $LDAP_BASEDN = _delquote($conf->val('password', 'basedn'));
my $LDAP_USER = _delquote($conf->val('password', 'binddn'));
my $LDAP_PASSWORD = _delquote($conf->val('password', 'bindpw'));
if(!$LDAP_HOST || !$LDAP_USER || !$LDAP_PASSWORD || !$LDAP_BASEDN){
    _err("$MYNAME:ldap_infomation not found");
    exit 1;
}

### OUTPUT ###
my $OUTPUT_USERMAPPINGXML = '/opt/secioss/etc/user-mapping.xml';

### Others
my $KEYFILE = _delquote($conf->val('password', 'keyfile'));
if (!-f $KEYFILE) {
    _err("$MYNAME:Others_infomation keyfile($KEYFILE) not found");
    exit 1;
}


### SUBROUTINE ###
sub _auditOutput {
    my $msg   = shift;

    openlog( $MYNAME, 'pid', 'local3' );
    syslog( 'info', $msg );
    closelog();
}

sub _output {
    my $level   = shift;
    my $msg     = shift;
    my $pid=$$; #プロセスIDを取得

    openlog( $MYNAME, 'pid', 'local4' );
    syslog( $level, "pid=$pid, ".$msg );
    closelog();
}

sub _debug {
    my $msg = shift;
    my $tenant  = shift;
    if(defined $tenant){
        $msg = "$tenant, $msg";
    }

    _output( 'debug', $msg );
}

sub _info {
    my $msg = shift;
    my $tenant  = shift;
    if(defined $tenant){
        $msg = "$tenant, $msg";
    }

    _output( 'info', $msg );
}

sub _err {
    my $msg = shift;
    my $tenant  = shift;
    if(defined $tenant){
        $msg = "$tenant, $msg";
    }

    _output( 'err', $msg );
}

sub _auditerr {
    my $msg = shift;

    _auditOutput( $msg );
}

##
##  _getLdapConnect
##  LDAP接続
##
sub _getLdapConnect
{
    my ($uri, $binddn, $bindpw) = @_;

    my $ldap = Net::LDAP->new($uri);
    if (!defined($ldap)) {
        return undef;
    }

    my $msg = $ldap->bind($binddn, password => $bindpw);
    if ($msg->code) {
        return undef;
    }

    return $ldap;
}

##
##  _getLdapObj
##  LDAPから情報を取得する
##
sub _getLdapObj
{
    my ($ldap, $basedn, $filter) = @_;

    my $ldapObject = $ldap->search(
        base => $basedn,
        filter => $filter,
    );

    my $errcode = $ldapObject->code;
    if ($errcode) {
        _err("Searching entry failed: ".$ldapObject->error."($errcode)");
        return -1;
    }

    my @list = ();
    if ($ldapObject->count) {
        foreach my $entry ($ldapObject->entries) {
            my %data = ();
            $data{'dn'} = $entry->dn;
            foreach my $attr ($entry->attributes) {
                my $values = $entry->get_value($attr, asref => 1);
                if (@{$values} > 1) {
                    $data{lc($attr)} = $values;
                } else {
                    $data{lc($attr)} = $values->[0];
                }
            }
            push(@list, \%data);
        }
    }

    return \@list;
}

##
## _delquote
## 文字列の先頭と末尾のダブルクォートを取り除く
##
sub _delquote
{
    my ($str) = @_;

    $str =~ s/^["']*//;
    $str =~ s/["']*$//;

    return $str;
}

##
## saveMappingXML
## user-mapping.xml を出力する
##
sub saveMappingXML
{
    my ($data, $file) = @_;
    my $x = new XML::Simple;
    my $xml = $x->XMLout($data, RootName => 'user-mapping');

    sysopen(my $fh, $file, O_WRONLY | O_CREAT | O_TRUNC) or return 1;
    print($fh $xml);
    close($fh);

    return 0;
}


# ##
# ## MAIN ##
# ##
_info("Start export privilegedid remote setting.");

my $ldap = _getLdapConnect($LDAP_HOST, $LDAP_USER, $LDAP_PASSWORD);
if (!$ldap) {
    _err('FILE: ' . __FILE__ . ' LINE: ' . __LINE__ . ' Ldap connect error: ' . $1);
    exit 1;
}

# ユーザー情報を取得する
my $user_filter = "(&(objectClass=inetOrgPerson)(seciossaccountstatus=active)(!(seciossPrivilegedIdType=*)))";
my $LDAP_userdata = &_getLdapObj($ldap, $LDAP_BASEDN, $user_filter);
if (ref($LDAP_userdata) ne 'ARRAY') {
    _err("Failed to Get User data");
    exit 1;
}

# 特権IDの情報を取得する
my $privilegedid_filter = "(&(objectClass=inetOrgPerson)(seciossaccountstatus=active)(seciossPrivilegedIdType=*))";
my $LDAP_privilegedconfig = &_getLdapObj($ldap, $LDAP_BASEDN, $privilegedid_filter);
if (ref($LDAP_privilegedconfig) ne 'ARRAY') {
    _err("Failed to Get Privilegedid data");
    exit 1;
}

# ターゲットの一覧を取得する
my $target_filter = "(&(objectClass=seciossSamlMetadata)(seciossSamlMetadataType=saml20-sp-remote))";
my $LDAP_target = &_getLdapObj($ldap, $LDAP_BASEDN, $target_filter);
if (ref($LDAP_target) ne 'ARRAY') {
    _err("Failed to Get Remote Access data");
    exit 1;
}
my $target_list = {};
foreach my $target (@$LDAP_target) {
    if (!defined $target->{'seciosssamlserializeddata'}) {
        next;
    }
    my $target_raw = unserialize($target->{'seciosssamlserializeddata'});
    if (!defined $target_raw->{'authproc'}->{'60'}->{'guac'}) {
        next;
    }
    my $guacdata = decode_json(decode_base64($target_raw->{'authproc'}->{'60'}->{'guac'}));
    my $idattr = $target_raw->{'simplesaml.nameidattribute'};
    my $pwdattr = 'none';
    if (defined $target_raw->{'authproc'}->{'10'}->{'attributename'}) {
        my $pwdattr = $target_raw->{'authproc'}->{'10'}->{'attributename'};
    }
    my %target_obj = (
        'servicename' => $target_raw->{'description'},
        'idattr' => $idattr,
        'pwdattr' => $pwdattr,
        'guac' => $guacdata
    );
    $target_list->{$target->{'cn'}} = \%target_obj;
}

#debug
# print Dumper $target_list;

if (!$target_list) {
    _err("No Such Object :Remote Access data");
    exit 1;
}

my @usermappinginfo = ();

# ユーザーの割当状況を確認
foreach my $user (@$LDAP_userdata) {
    if (defined $user->{$ATTR_PRIVILEGEDROLE}) {
        my $password = seciossDecPasswd($user->{$ATTR_ENCRYPTEDPASSWORD}, $KEYFILE);
        my $authorize = {
            'authorize' => {
                'username' => $user->{'uid'},
                'password' => $password,
            }
        };

        my $roledata = $user->{$ATTR_PRIVILEGEDROLE};
        my @rolelist = ();
        if (ref($roledata) ne 'ARRAY') {
            @rolelist = ($roledata);
        } else {
            @rolelist = @{$roledata};
        }
        my @connections = ();
        # 割当サービス情報を作成
        foreach my $role (@rolelist) {
            my $rolejson = decode_json($role);

            # $authorize->{'authorize'}->{'connection'}
            my $service = $target_list->{$rolejson->{'serviceid'}};
            my $connection = {
                'name' => $rolejson->{'serviceid'},
                'protocol' => [ $service->{'guac'}->{'protocol'} ]
            };
            # リモートアクセス先へ送信するデータ
            my @params = ();
            foreach my $key (keys($service->{'guac'})) {
                if ($key eq 'protocol') {
                    next;
                }
                push(@params, {
                    'name' => $key,
                    'content' => $service->{'guac'}->{$key},
                });
            }
            # アカウント情報
            foreach my $privilegedid (@$LDAP_privilegedconfig) {
                if ($privilegedid->{'seciossloginid'} ne $rolejson->{'privilegedid'}) {
                    next;
                }
                if (defined $privilegedid->{$service->{'idattr'}}) {
                    push(@params, {
                        'name' => 'username',
                        'content' => $privilegedid->{$service->{'idattr'}},
                    });
                }
                if (defined $privilegedid->{$ATTR_ENCRYPTEDPASSWORD}) {
                    push(@params, {
                        'name' => 'password',
                        'content' => seciossDecPasswd($privilegedid->{$ATTR_ENCRYPTEDPASSWORD}, $KEYFILE),
                    });
                }
            }

            $connection->{'param'} = \@params;
            push(@connections, $connection);
        }
        $authorize->{'authorize'}->{'connection'} = \@connections;
        push(@usermappinginfo,$authorize);
    }
}

if (saveMappingXML(@usermappinginfo, $OUTPUT_USERMAPPINGXML)) {
    _err("Failed to Save File: $OUTPUT_USERMAPPINGXML");
    exit 1;
}

_info("End export privilegedid remote setting.");

exit;
