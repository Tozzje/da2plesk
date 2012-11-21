<?php
//test git commit
include("includes/color.class.php");
include("includes/other.class.php");
include("includes/email.class.php");
include("includes/backup.class.php");

define("IPv4", "83.137.145.174");
define("IPv6", "2a01:1b0:7999:402::174");

define("BACKUP_PATH", "/tmp/feel");
define("EMAIL_PWS", "/var/www/da/data/email");

// Do not log notices and warnings (imap_open logs notices and warnings on wrong login)
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

$backup = new Backup(BACKUP_PATH);
$other = new Other();
$mail = new Email(EMAIL_PWS);

//$backup->getAdditionalDomains();

$password = $other->generatePassword();

$domain = $backup->getDomain();
$tld = explode(".", $domain);
$tld = $tld[0];

$ip = $backup->getIP();
$username = $backup->getUsername();

/* BEGIN PRIMARY DOMAIN */
echo "/opt/psa/bin/customer -c $username -name $username -passwd $password\n";
echo "/opt/psa/bin/subscription -c $domain -owner $username -service-plan \"Hosters.nl\" -ip 83.137.145.174,2a01:1b0:7999:402::174 -login $username -passwd $password\n";
echo "\n";
echo "/usr/bin/find " . $backup->getPath() . "/domains/" . $domain . "/ -type f -print | xargs -I {} sed -i \"s@/home/" . $username . "/domains/" . $domain . "/public_html@/var/www/vhosts/" . $domain . "/httpdocs@g\" {}\n";
echo "/usr/bin/find " . $backup->getPath() . "/domains/" . $domain . "/ -type f -print | grep configuration.php | xargs -I {} sed -i \"s@ftp_enable = '1'@ftp_enable = '0'@g\" {}\n";
echo "cd " . $backup->getPath() . "/domains/" . $domain . "/public_html; /usr/bin/ncftpput -R -u$username -p$password localhost httpdocs .\n";

foreach ($backup->getSubdomains($domain) as $sub) {
    echo "/opt/psa/bin/subdomain -c $sub -domain $domain -www-root /httpdocs/$sub -php true\n";
};
/* END PRIMARY DOMAIN */

/* START ADDITIONAL DOMAINS */
foreach ($backup->getAdditionalDomains() as $extradomain) {
    echo "/opt/psa/bin/site -c $extradomain -hosting true -hst_type phys -webspace-name $domain -www-root domains/$extradomain\n";
    
    echo "/usr/bin/find " . $backup->getPath() . "/domains/" . $extradomain . "/ -type f -print | xargs -I {} sed -i \"s@/home/" . $username . "/domains/" . $extradomain . "/public_html@/var/www/vhosts/" . $domain . "/domains/" . $extradomain . "@g\" {}\n";
    echo "/usr/bin/find " . $backup->getPath() . "/domains/" . $extradomain . "/ -type f -print | grep configuration.php | xargs -I {} sed -i \"s@ftp_enable = '1'@ftp_enable = '0'@g\" {}\n";
    echo "cd " . $backup->getPath() . "/domains/" . $extradomain . "/public_html; /usr/bin/ncftpput -R -u$username -p$password localhost domains/" . $extradomain . " .\n";

    foreach ($backup->getSubdomains($extradomain) as $sub) {
        echo "/opt/psa/bin/subdomain -c $sub -domain $extradomain -www-root /domains/$extradomain/$sub -php true\n";
    };
    
}

/* END ADDITIONAL DOMAINS */

foreach ($backup->getAdditionalDomains(FALSE) as $extradomain) {

    foreach ($backup->getAliases($extradomain) as $alias) {
        echo "/opt/psa/bin/domalias -c $alias -domain $extradomain\n";
    }

    foreach ($backup->getPointers($extradomain) as $alias) {
        echo "/opt/psa/bin/site -c $alias -hosting true -hst_type phys -webspace-name $domain -www-root domains/$alias\n";
        // TODO: Dit moet een 301 worden.
        // redirect 301 / http://www.you.com/
        $tmpfname = tempnam("/tmp", "damigration");
        $handle = fopen($tmpfname, "w");
        fwrite($handle, "Redirect 301 / http://www." . $extradomain . "/\n");
        fclose($handle);

        echo "/usr/bin/ncftpput -c -u$username -p$password localhost domains/$alias/.htaccess < $tmpfname\n";
        echo "rm $tmpfname\n";
    }

    $popresult = false;
    foreach ($backup->getPOP($extradomain) as $pop) {
        $mailpw = $mail->getPassword($pop . "@" . $extradomain);
        if ($mailpw == false) {
            $mailpw = $password;
        };
        echo "/opt/psa/bin/mail -c $pop@$extradomain -mailbox true -passwd $mailpw -passwd_type plain\n";
        echo "/opt/psa/bin/spamassassin -u $pop@$extradomain -status true -hits 5 -action del\n";

        $popresult = true;
    }

    if ($popresult == true) { echo "/usr/bin/copymail $extradomain $ip\n"; };
    
    foreach ($backup->getForward($extradomain) as $forward) {
        echo "/opt/psa/bin/mail -c " . $forward['account'] . "@$extradomain -mailbox false -forwarding true -forwarding-addresses add:" . $forward['to'] . "\n";
        echo "/opt/psa/bin/spamassassin -u " . $forward['account'] . "@$extradomain -status true -hits 5 -action del\n";
    }
}

foreach ($backup->getDatabaseList() as $db) {
    echo "/opt/psa/bin/database -c $db -domain $domain -type mysql\n";
    echo "/bin/sed -i \"s@/home/" . $username . "/domains/" . $domain . "/public_html@/var/www/vhosts/" . $domain . "/httpdocs@g\" " . $backup->getPath() . "/backup/" . $db . ".sql\n";
    echo "/usr/bin/mysql -uadmin -p`cat /etc/psa/.psa.shadow` $db < " . $backup->getPath() . "/backup/" . $db . ".sql\n";

    foreach ($backup->getDatabaseLogin($db) as $user) {
        echo "/opt/psa/bin/database -u $db -add_user " . $user['user'] . " -passwd $password\n";
        echo "/usr/bin/mysql -uadmin -p`cat /etc/psa/.psa.shadow` mysql -e \"UPDATE mysql.user SET Password = '" . $user['pass'] . "' WHERE User = '" . $user['user'] . "'\"\n";
        echo "/usr/bin/mysql -uadmin -p`cat /etc/psa/.psa.shadow` mysql -e \"FLUSH PRIVILEGES\"\n";
    };
}




// plesk commandos
// sed draaien
// kijken of we sql pw's kunnen halen uit PHP
// joomla ftp_enable
// copymail draaien
?>
