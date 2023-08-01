<?php


  require_once ('/var/bitrix24_include/crestBD.php');
  require_once ('/var/bitrix24_include/common.php');
  require_once("/var/bitrix24_include/buildHandler.php");
  require_once("/var/bitrix24_include/buildPayment.php");


  if(isset($_REQUEST['b24_url'], $_REQUEST['pk_url'], $_REQUEST['pk_secret_seed'], $_REQUEST['auth']))
  {
    echo '<h3>Пожалуйста, подождите .....</h3>';  
    $auth_arr           =     json_decode($_POST['auth'],true);                             // json массив с аутентификационными данными из битрикс24
    $pk_url             =     htmlspecialchars($_POST["pk_url"]);                           // адрес личного кабинета
    $pk_secret_seed     =     htmlspecialchars($_POST["pk_secret_seed"]);                   // секретное слово для кабинета Paykeeper
    $access_token       =     htmlspecialchars($auth_arr["AUTH_ID"]);                       // токен  доступа
    $expires            =     htmlspecialchars(time());                                     // время появления токена доступа
    $expires_app        =     htmlspecialchars('3600');                                     // время жизни токена доступа
    $b24_url            =     htmlspecialchars($_POST["b24_url"]);                          // адрес домена Битрикс24
    $member_id          =     htmlspecialchars($auth_arr["member_id"]);                     // параметр member_id выдаваемы при установке приложения
    $refresh_token      =     htmlspecialchars($auth_arr["REFRESH_ID"]);                    // токен для обновления
    $app_token          =     htmlspecialchars('Не передается для 2-го типа');              // уникальный токен приложения(не нужен) 
    $shop_key           =     GetRandomString(24, 'password');                              // уникальный код магазина
    $rest_client_id     =     "app.6156f749cb3206.36191493";                                // идентификатор приложения в битрикс24
    $rest_client_secret =     "tYT5UAhSi1MKhcGXDBDsUqBxFvvadZBpTLS6IMNgLOseYiaOZv";         // секретное слово для приложения битрикс24

    if (substr($b24_url,-1)=='/')
    {
      $b24_url = substr($b24_url, 0, -1);
    }

    if (!file_exists(__DIR__."/log/".substr($b24_url,7))) {
      mkdir(__DIR__.'/log/'.substr($b24_url,7), 0775, true);
    }

    echo '<pre>';
    echo '<h3>Подключение к базе данных .....</h3>'; 
    ob_flush();
    flush();   
    $sth = StartDB();
    echo '<h3>Получение данных .....</h3>';  
    ob_flush();
    flush();  
    $server_by_url =  GetServerByURL($sth["get_server_by_url"],$b24_url);

    if (is_array($server_by_url) && count($server_by_url)==0)     // если строки в БД нет, то создаем новую запись
    {
      echo '<h3>Добавление нового клиента .....</h3>'; 
      ob_flush();
      flush();  

      $server_id = AddServer(
                              $sth['add_server'],
                              $pk_url,
                              $pk_secret_seed,
                              $b24_url,                                
                              $access_token,
                              $expires,
                              $expires_app,
                              $refresh_token,
                              $app_token,                                
                              $member_id,
                              $rest_client_id,
                              $rest_client_secret,
                              $shop_key,
                              0,
                              '',
                              '',
                              ''
                            );
    } 
    elseif (is_array($server_by_url) && count($server_by_url)==1)       // если в БД совпала одна строка , то обновляем её
    {
      echo '<h3>Клиент найден .....</h3>';  
      ob_flush();
      flush();  
      echo '<h3>Обновление данных клиента .....</h3>';  
      ob_flush();
      flush(); 
      
      $server_id = UpdateServer(
                                  $sth['update_server'],
                                  $pk_url,
                                  $pk_secret_seed,
                                  $b24_url,                                
                                  $access_token,
                                  $expires,
                                  $expires_app,
                                  $refresh_token,
                                  $app_token,                                
                                  $member_id,
                                  $rest_client_id,
                                  $rest_client_secret,
                                  $shop_key,
                                  0,
                                  '',
                                  '',
                                  ''
                                );
                               
    }
    else 
    {
      echo "Запись БД для адреса  $b24_url  не уникальна! Обратитесть в службу техподдежки Paykeeper";    // если совпавших строк больше одной, то умираем
      ob_flush();
      flush();  
      file_put_contents(__DIR__.'/log/'.substr($b24_url,7)."/install.log", date('Y-m-d\TH:i:s')."Запись БД для адреса  $b24_url  не уникальна! Обратитесть в службу техподдежки Paykeeper".PHP_EOL, FILE_APPEND);
      die();
    }


      
            /****************************Проверка наличия и создание платежной системы и ее обработчика ********************************/ 
            
            

    if ($server_id > 0) // Если запись БД была обновлена, то пытаемся создать обработчик ПС
    {
      echo '<h3>Обновление авторизационных данных .....</h3>'; 
      ob_flush();
      flush();  
      $auth_data = authDataFromDB($sth, $server_id);
      file_put_contents(__DIR__.'/log/debug.log', print_r($auth_data,true), FILE_APPEND);  //debug
      $b24_domain         = $auth_data['b24_url'];
      $paySystemName      = "Paykeeper";
      $handlerName        = "Paykeeper";
      $handlerCode        = "Paykeeper";
      $clientId           =  $auth_data['C_REST_CLIENT_ID'];
      $client_secret      =  $auth_data['C_REST_CLIENT_SECRET'];
      $middleapp          = "https://bitrix24.paykeeper.ru/middleapp.php?shop_id=".$auth_data["server_id"]."&shop_key=".$auth_data["shop_key"];
      echo '<h3>Запрос существующих типов плательщиков .....</h3>'; 
      ob_flush();
      flush(); 
      $paysystemlist = CRestBD::call('sale.paysystem.list', $auth_data);
      //file_put_contents(__DIR__.'/log/debug.log', print_r($paysystemlist,true), FILE_APPEND); //debug
      echo '<h3>Запрос наличия платежной системы Paykeeper .....</h3>'; 
      ob_flush();
      flush();  

      $sale_persontype_list = CRestBD::call('sale.persontype.list', $auth_data);
      if (isset($sale_persontype_list['error']))
      {
        echo'Не могу получить список типов плательщиков!'.PHP_EOL;
        print_r($sale_persontype_list['error']);
        die();
      }
      $crm_persontype_list = CRestBD::call('crm.persontype.list', $auth_data);
      if (isset($sale_persontype_list['error']))
      {
        echo'Не могу получить список типов плательщиков!'.PHP_EOL;
        print_r($sale_persontype_list['error']);
        die();
      }
      /** Формирую массив идентификаторов типов плательщиков для счетов */
        foreach($crm_persontype_list['result'] as $key=>$item)
        {
          $persontype_arr['CRM_INVOICE'][$item['NAME']] = $item['ID'];
        }

      /** Формирую массив идентификаторов типов плательщиков для заказов */
      foreach($sale_persontype_list['result'] as $personTypes)
      {
        foreach($personTypes as $key=>$item)
        {
          $persontype_arr['ORDER'][$item['code']] = $item['id'];
        }
      }
      $paysystem_exist = false;
      $ps_id = 0;
      $new_handler_id = 0;
      $invoice_paysystem_id = 0;
      $phisic_paysystem_id = 0;
      $legal_paysystem_id = 0;        
      $ps_ar = [];// массив с номерами ПС с названием Paykeeper
      $names_ar =['Paykeeper','Paykeeper_invoice','Paykeeper_order','Paykeeper (Paykeeper)','paykeeper'];
      foreach($paysystemlist as $r) // разбираем массив ПС существующих в битрикс24 и ищем  имя Paykeeper, если находим будем удалять
      {
        foreach($r as $v=>$i)
        {
          if (isset($i['NAME']))
          {
            if (in_array($i['NAME'],$names_ar))
            {
              $ps_ar[] = $i['ID'];
            }
          }
        }
      }
      if (count($ps_ar)>0)
      {
        echo '<h3>Платежная система Paykeeper найдена  и будет переустановлена.....</h3>'; 
        ob_flush();
        flush();  
        for ($k=0;$k<count($ps_ar);$k++)
        {
        $delete_payment_status = CRestBD::call('sale.paysystem.delete', $auth_data, array("id" =>$ps_ar[$k]));
        //file_put_contents(__DIR__.'/log/debug.log', '$delete_payment_status'.print_r($delete_payment_status,true), FILE_APPEND);  //debug
        // echo '$delete_payment_status - '.$ps_ar[$k].PHP_EOL;// эти строки  раскоментировать для отладки!!!
        // print_r($delete_payment_status);// эти строки  раскоментировать для отладки!!!
        usleep(500);          
        }
      }
      echo '<h3>Проверка наличия обработчика платежной системы Paykeeper .....</h3>'; 
      ob_flush();
      flush();  
      $handler_list = CRestBD::call('sale.paysystem.handler.list', $auth_data);
      //file_put_contents(__DIR__.'/log/debug.log', '$handler_list'.print_r($handler_list,true), FILE_APPEND);  //debug
      $handler_exist = false;
      $handler_id = 0;
      $hd_ar = [];// массив с номерами обработчиков ПС с названием Paykeeper
      foreach($handler_list as $r) // разбираем массив хендлеров существующих в битрикс24 и ищем  имя Paykeeper, если находим будем удалять
      {
        foreach($r as $v=>$i)
        {
          if (isset($i['NAME']))
          {
            if ($i['NAME']=="Paykeeper")
            {
              //echo $i['ID'].PHP_EOL;
              $hd_ar[] = $i['ID'];
            }
          }
        }
      } 
      
      if (count($hd_ar)>0)
      {
        echo '<h3>Обработчика платежной системы Paykeeper найден и будет переустановлен .....</h3>'; 
        echo '<h3>Удаление обработчика .....</h3>'; 
        ob_flush();
        flush();  
        for ($i=0;$i<count($hd_ar);$i++)
        {
        $delete_handler_status = CRestBD::call('sale.paysystem.handler.delete', $auth_data, array('id' => $hd_ar[$i]));
        usleep(300);
        }
        //file_put_contents(__DIR__.'/log/debug.log', '$delete_handler_status'.print_r($delete_handler_status,true), FILE_APPEND);  //debug
        // echo 'delete_handler_status'.PHP_EOL;// эти строки  раскоментировать для отладки!!!
        // print_r($delete_handler_status);// эти строки  раскоментировать для отладки!!!
      }
      echo '<h3>Создание обработчика .....</h3>'; 
      ob_flush();
      flush();  
      $handlerObject = buildHandler($handlerName,$handlerCode,$middleapp);   
      $add_handler_status = CRestBD::call('sale.paysystem.handler.add', $auth_data, $handlerObject);
      // echo 'add_handler_status'.PHP_EOL;// эти строки  раскоментировать для отладки!!!
      // print_r($add_handler_status);// эти строки  раскоментировать для отладки!!!
      if (isset($add_handler_status['result']))
      {
        $new_handler_id = $add_handler_status['result'];
        // echo '$new_handler_id - '.$new_handler_id;
      } 


      if(!$paysystem_exist) 
      {
        echo '<h3>Создание платежной системы .....</h3>'; 
        ob_flush();
        flush();  
        usleep(500);
        $paysystem_json=[];
        foreach($persontype_arr as $entity_registry_type => $entity_registry_type_arr)
        {
          foreach($entity_registry_type_arr as $person_type_id => $val)
          {
            $paysystem_object = buildPayment($paySystemName,$handlerCode,$val,$entity_registry_type);
            $add_paysystem_status = CRestBD::call('sale.paysystem.add', $auth_data, $paysystem_object); 
            // echo '$add_paysystem_status'.PHP_EOL;// эти строки  раскоментировать для отладки!!!
            // print_r($add_paysystem_status);// эти строки  раскоментировать для отладки!!! 
            if (isset($add_paysystem_status['result']))
            {
              $paysystem_json[$add_paysystem_status['result']]['ENTITY_REGISTRY_TYPE'] = $entity_registry_type;
              $paysystem_json[$add_paysystem_status['result']]['PERSON_TYPE_ID'] = $val;
            }
              elseif(isset($add_paysystem_status['error']))
            {
              echo('Получена ошибка при установке платежной системы. Попробуйте переустановить позже, или обратитесь в службу поддержки.');
            }
          }
        }
        $paysystem_string = json_encode($paysystem_json);
      }   
      $server_id = UpdateServer(
                                  $sth['update_server'],
                                  $pk_url,
                                  $pk_secret_seed,
                                  $b24_url,                                
                                  $access_token,
                                  $expires,
                                  $expires_app,
                                  $refresh_token,
                                  $app_token,                                
                                  $member_id,
                                  $rest_client_id,
                                  $rest_client_secret,
                                  $shop_key,
                                  $new_handler_id,
                                  $paysystem_string,
                                  '',
                                  ''
                                );
      echo '<h3>Готово! .....</h3>';
      ob_flush();
      flush();  
      file_put_contents(__DIR__.'/log/'.substr($b24_url,7)."/install.log", date('Y-m-d\TH:i:s')." OK pk_url ".$pk_url.PHP_EOL, FILE_APPEND); 
    }
    echo 
      '<script src="//api.bitrix24.com/api/v1/"></script>
        <script>
          BX24.init(function(){
          BX24.installFinish();
          });
        </script>';
    die();  
  }
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-+0n0xVW2eSR5OomGNYDnhzAbDsOXxcvSN1TPprVMTNDbiYZCxYbOOl7+AMvyTG2x" crossorigin="anonymous">
    <title>Установка модуля Paykeeper</title>
</head>
<body>
<div class="container mt-5">
    <div class="row">
        <div class="col-sm-3">
            <img src="images/logo.png" class="rounded float-left" alt="logo" width = "100px " height = "100px " >
        </div>
        <div class="col-sm-6">
            <h3 class="mx-auto d-block">Регистрация в системе </h3>
            <form action="install.php" accept-charset="utf-8" method="post">
                <div class="mb-3">
                    <label for="b24_url" class="form-label">Полный адрес портала Bitrix24 (Например, https://example.bitrix24.ru)</label>
                    <input type="text" class="form-control" id="b24_url" name="b24_url" value="">
                </div>
                <div class="mb-3">
                    <label for="pk_url" class="form-label">Адрес платежной формы (Например, https://example.server.paykeeper.ru/create) </label>
                    <input type="text" class="form-control" id="pk_url" name="pk_url"  value="">
                </div>
                <div class="mb-3">
                    <label for="pk_secret_seed" class="form-label">Секретный код из личного кабинета </label>
                    <input type="text" class="form-control" id="pk_secret_seed" name="pk_secret_seed"  value="">
                </div>
                <input type="hidden"  id="auth" name="auth"  value=
                <?php
                if($_REQUEST['AUTH_ID'] && $_REQUEST['REFRESH_ID'])
                {
                $auth_arr = ['AUTH_ID'=>$_REQUEST['AUTH_ID'],'REFRESH_ID'=>$_REQUEST['REFRESH_ID'],'member_id'=>$_REQUEST['member_id'] ];
                echo json_encode($auth_arr); 
                }
                ?>
                >
                <button type="submit" class="btn btn-primary">Регистрация</button>
            </form>
            <div class="mb-3">
            </div>
        </div>
        <div class="col-sm-3">
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.1/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-gtEjrD/SeCtmISkJkNUaaKMoLD0//ElJ19smozuHV6z3Iehds+3Ulb9Bn9Plx0x4"
        crossorigin="anonymous">
</script>
</body>
</html>