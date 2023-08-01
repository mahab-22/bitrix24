<?php
    require_once('/var/bitrix24_include/common.php');
    require_once('/var/bitrix24_include/crestBD.php');
    try {
      $DB = new PDO("mysql:dbname=b24;host=127.0.0.1;charset=UTF8", "root", "zx1BwNdw");
      $DB->exec("set names utf8");
      $DB->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    } catch (Exception $m) 
    {
      file_put_contents('log.log',print_r("DB connection cant be started",true),FILE_APPEND);
      die ("DB connection can't be started, {$m->getMessage()}\n");
    };
    $timestampPlus10Days = 1209600;
    $res = $DB->query("select * from servers;");
    $ins='';
    $diff =0;
    while ($row = $res->fetch())
    {
        $diff =time()-$row['expires'];

        
        if ($diff > 1209600)
        {
            // echo '<pre>';
            // print_r($row);
            // echo '</pre>';
            // echo (PHP_EOL."Difference = ".$diff. PHP_EOL);
            // echo PHP_EOL . date("d-m-Y H:i:s", $row['expires']) . PHP_EOL;

            $auth = 
            [
                'C_REST_CLIENT_ID'         =>   $row['rest_client_id'],
                'C_REST_CLIENT_SECRET'     =>   $row['rest_client_secret'],
                'refresh_token'            =>   $row['refresh_token'],
            ];
            $response = CRestBD::GetNewAuth($auth);
            // echo '<pre>';
            // print_r($response);
            // echo '</pre>';
            if ($response['error'])
            {
                file_put_contents('/var/www/bitrix24.paykeeper.ru/log/cron.log',date("d.m.y H:i:s") . " refresh_token operation: " . $row["pk_url"] . 
                " " . $response["error_description"]  . PHP_EOL ,FILE_APPEND);

            }
            else
            {

                $ins = 'UPDATE servers SET  access_token="' . $response['access_token'] . '", refresh_token="' . $response['refresh_token']
                . '", expires="' . $response['expires'] . '" WHERE server_id=' . $row['server_id'] .';';
                //echo  $ins . PHP_EOL;
                $DB->exec($ins);
            }
        }

    }

    $DB=null;
    $res=null;
?>