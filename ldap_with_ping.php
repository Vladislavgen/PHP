<?php

// Код для тестирования подключения к LDAP
/**

 * подключение к AD
 *
 * @param $connect
 * @return bool|resource
 */


# Функция проверки доступности DC
function serviceping($host, $port=636, $timeout=1)
{
        $op = fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$op) return 0; //DC is N/A
    else {
    fclose($op); //Явное закрытие открытого соединения сокета
    return 1; //DC Готов к подключению, можно использовать ldap_connect
    }
}


function getLdap($connect, $host=null, $port=null){

    // # DYNAMIC DC LIST, reverse DNS lookup sorted by round-robin result (Работает round-robin?)
    $dclist = gethostbynamel('ldap.bingo-boom.ru');
    foreach ($dclist as $k => $dc) if (serviceping($dc) == true) break; else $dc = 0;
    // После этого цикла, либо будет хотя бы один DC, который доступен в настоящее время, либо $dc вернет bool false, а следующая строка остановит программу от дальнейшего выполнения
    if (!$dc) exit("NO DOMAIN CONTROLLERS AVAILABLE AT PRESENT, PLEASE TRY AGAIN LATER!"); //user being notified

    //$ldaphost = 'ldaps://ldap.bingo-boom.ru';
    $ldapport = "389";

    if ($connect == 'old') {
        $ldaphost = 'ldap.bingo-boom.ru';
        $ldapport = "389";
    }

    if ($host) {
        $ldaphost = $host;
    }

    if ($port) {
        $ldapport = $port;
    }

    $domain = "@bbgroup.local";
    $user='passuser';
    $password = 'RDoPdu9RBuJ2KBr6Uoig';
    if (php_sapi_name() == "cli") echo 'Debug: run ldap_connect()' . PHP_EOL;

    // - - 
    $link_identifier = ldap_connect($dc, $ldapport) or die("DC N/A, PLEASE TRY AGAIN LATER.");
    ldap_set_option($link_identifier, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($link_identifier, LDAP_OPT_REFERRALS, 0);

    try {
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_bind()' . PHP_EOL;
        ldap_bind($link_identifier, $user . $domain, $password);
    } catch (Exception $e) {

        echo 'LDAP ERROR: ' . $e->getMessage(); exit();
        return false;
    }

    return $link_identifier;
}




/**
 * Получение данных из AD
 * @param $department
 * @param bool $login
 * @return array
*/
function searchAD($connect, $department, $login=false, $host=null, $port=null){
    $link_identifier = getLdap($connect, $host, $port);

    if ($link_identifier) {
        $res = getManagersFromLdap($department, $login, $link_identifier);
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_close()' . PHP_EOL;
        ldap_close($link_identifier);

        if (!$res['count']) {
            die('Data with LDAP 0. Data' . json_encode($res));
        }

        return $res;
    }

    die('LDAP data not received');
}

function getManagersFromLdap($department, $login, $link_identifier) {

    $base_dn = 'OU=Бинго Бум,OU=BBGROUP,DC=bbgroup,DC=local';

    $ldap_query = [
        1  => 'Partner_department,OU=Партнёрский отдел',
        2  => 'dep_personal,OU=Департамент управления персоналом',
        3  => 'personal_hr,OU=Отдел персонала,OU=Департамент управления персоналом',
        4  => 'personal_tb,OU=Отдел охраны труда,OU=Департамент управления персоналом',
        5  => 'personal,OU=Отдел кадров,OU=Департамент управления персоналом',
        6  => 'finansist,OU=Финансовый департамент',
        7  => 'ORSPPSBK,OU=Отдел регистрации и сопровождения ППС БК',
        8  => 'ovka,OU=Отдел внутреннего контроля и аудита,OU=Финансовый департамент',
        9  => 'opvz,OU=Департамент общеправовых вопросов и законотворчества',
        10 => 'contract_law,OU=Отдел договорно-правовой работы,OU=Департамент общеправовых вопросов и законотворчества',
        11 => 'internetion_law,OU=Отдел международного права,OU=Департамент общеправовых вопросов и законотворчества',
        12 => 'organization_law,OU=Отдел некоммерческих организаций и законотворчества,OU=Департамент общеправовых вопросов и законотворчества',
        13 => 'claims,OU=Департамент судебной работы и взаимодействия с гос. органами',
        14 => 'criminal_law,OU=Отдел административной и уголовной практики,OU=Департамент судебной работы и взаимодействия с гос. органами',
        15 => 'civil_procedure,OU=Отдел гражданского судопроизводства,OU=Департамент судебной работы и взаимодействия с гос. органами'
    ];

    $res = ['count' => 0];

    $departments = [$department];

    if ($department == 6) {
        $departments = [6,7,8,9,10];
    }

    foreach ($departments as $department) {
        $filter = $login
            ? '(&(objectClass=user)(objectCategory=person)(sAMAccountName=' . $login . '))'
            : '(&(objectClass=user)(objectCategory=person)(memberof=CN=' . $ldap_query[$department] . ',' . $base_dn . '))';
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_search()' . PHP_EOL;
        $result_identifier = ldap_search($link_identifier, $base_dn, $filter, [
            'cn',
            'samaccountname',
            'mail'
        ]);
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_get_entries()' . PHP_EOL;
        $info = ldap_get_entries($link_identifier, $result_identifier);

        for ($i = 0; $i < $info['count']; $i++) {
            $res[] = [
                'login' => $info[$i]['samaccountname'][0],
                'name'  => $info[$i]['cn'][0],
                'email' => $info[$i]['mail'][0]
            ];
        }

        if ($info['count']) {
            $res['count']+= $info['count'];
        }
    }

    return $res;
}



function getQuery($connect, $base_dn, $filter) {
    $link_identifier = getLdap($connect);

    if ($link_identifier) {
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_search()' . PHP_EOL;
        $result_identifier = ldap_search($link_identifier, $base_dn, $filter, [
            'cn',
            'samaccountname',
            'mail'
        ]);
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_get_entries()' . PHP_EOL;
        $info = ldap_get_entries($link_identifier, $result_identifier);
        if (php_sapi_name() == "cli") echo 'Debug: run ldap_close()' . PHP_EOL;
        ldap_close($link_identifier);

        if (!$info['count']) {
            die('Data with LDAP 0. Data: ' . json_encode($info));
        }

        return $info;
    }

    die('LDAP data not received');
}

function authWithLdap($connect, $username, $password) {
    $link_identifier = getLdap($connect);
    $domain = "@bbgroup.local";
    if (php_sapi_name() == "cli") echo 'Debug: run ldap_bind()' . PHP_EOL;
    $ldapbind = ldap_bind($link_identifier, $username . $domain, $password);

    if ($ldapbind) {
        return 'User is authorized';
    } else {
        return 'ERROR:' .  ldap_error($link_identifier);
    }
}

if (php_sapi_name() == "cli") {
    $connect = 'new';
    $type = 'department';
    $department = 1;

    $username = null;
    $password = null;
    $host = null;
    $port = null;

    extract(getopt(null, ['connect:', 'type:', 'department:', 'username:', 'password:', 'host:', 'port:']));


    if ($type == 'department') {
      print_r(searchAD($connect, ($department ? : 5), false, $host, $port));
    } else if ($type == 'user' && $username && $password) {
        echo authWithLdap($connect, $username, $password);
    }

} else {

    $base_dn = $_POST['base_dn'] ? : 'OU=Бинго Бум,OU=BBGROUP,DC=bbgroup,DC=local';
    $filter = $_POST['filter'] ? : '(&(objectClass=user)(objectCategory=person)(memberof=CN=ovka,OU=Отдел внутреннего контроля и аудита,OU=Финансовый департамент,' . $base_dn . '))';
    $username = $_POST['username'];
    $password = $_POST['password'];
    $connect = $_POST['connect'];
    $type = $_POST['type'];
?>

    <form method="post">
        <div style="padding: 0px; border: 1px solid gray">
            <table width="100%">
                <tr>
                    <td>Коннект</td>
                    <td>
                        <label title="ldaps://ldap.bingo-boom.ru:636"><input type="radio" name="connect" value="new"<?= ($connect == 'new' || !$connect ? ' checked="checked"' : '')?>>ssl</label>
                        <label title="ldap.bingo-boom.ru:389"><input type="radio" name="connect" value="old"<?= ($connect == 'old' ? ' checked="checked"' : '')?>>no ssl</label>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 20px;"><label><input type="radio" name="type" value="param"<?= ($type == 'param' || !$type ? ' checked="checked"' : '')?>>Забрать данные по параметрам</label></td>
                </tr>
                <tr>
                    <td style="width: 40px; font-weight: bold"><label for="base_dn">base_dn</label></td>
                    <td><input type="text" name="base_dn" id="base_dn" value="<?= $base_dn ?>" style="width: 70%"></td>
                </tr>
                <tr>
                    <td style="width: 40px;font-weight: bold"><label for="filter">filter</label></td>
                    <td><input type="text" name="filter" id="filter" value="<?= $filter ?>" style="width: 70%"></td>
                </tr>
                <tr>
                    <td colspan="2" style="padding-top: 20px; font-size: 12pt;"><label><input type="radio" name="type" value="user"<?= ($type == 'user' ? ' checked="checked"' : '')?>>Авторизуйтесь под пользователем</label></td>
                </tr>
                <tr>
                    <td style="width: 40px;font-weight: bold"><label for="username">username</label></td>
                    <td><input type="text" name="username" id="username" value="<?= $username ?>" style="width: 70%"></td>
                </tr>
                <tr>
                    <td style="width: 40px;font-weight: bold"><label for="password">password</label></td>
                    <td><input type="text" name="password" id="password" value="<?= $password ?>" style="width: 70%"></td>
                </tr>
            </table>
            <input type="submit">
        </div>
    </form>
<?php
    $connect = $_POST['connect'] ? : ($_GET['old'] ? 'old': 'new');

    if ($type == 'param') {
        echo '<pre>';print_r(getQuery($connect, $base_dn, $filter)); echo '<pre>';
    } else if ($_GET['id']) {
        // Раскоментить
        echo '<pre>';print_r(searchAD($connect, ($_GET['id'] ? : 5), null, $_GET['host'], $_GET['port']));echo '<pre>'; // , 'vv_bayazov'
    } else if ($type == 'user' && $username && $password) {
        echo authWithLdap($connect, $username, $password);
    }
}
?>


