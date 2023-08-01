<?php

    require_once ('/var/bitrix24_include/common.php');	
    require_once ('/var/bitrix24_include/crestBD.php');
    //file_put_contents(__DIR__."/log/error.log", print_r($_POST,true), FILE_APPEND);
    if(empty($_POST['id']) || empty($_POST['orderid']) || empty($_POST['clientid']) || empty($_POST['sum']) || empty($_POST['service_name']) || empty($_POST['service_name']))
    {
        echo '** Отсутствуют необходимые входные параметры запроса **';
        die();
    }
    $orderid = htmlspecialchars($_POST['orderid']);    
    $pk_payment_id = htmlspecialchars($_POST['id']);
    $clientid = $_POST['clientid'];
    $sum = number_format(htmlspecialchars($_POST['sum']),2,".","");
    $service_name = htmlspecialchars($_POST['service_name']);    
    $sign = htmlspecialchars($_POST['key']);
    $service_name_split = explode('|', $service_name);
    if (is_array($service_name_split)){
        if (count($service_name_split) ==4){
            $server_id = $service_name_split[0];
            $payment_id = $service_name_split[1];
            $transaction_id = $service_name_split[2]; 
            $paysystem_id = $service_name_split[3];            
        }
    }
    if ($service_name=='' || $server_id=='' || $payment_id=='' || $transaction_id=='' || $paysystem_id=='')
    {
        file_put_contents(__DIR__.'/log/error.log', PHP_EOL.'Отсутствуют параметры поля service_name'. PHP_EOL.print_r($_POST,true),FILE_APPEND);
        echo 'Отсутствуют необходимые входные параметры запроса!';
        die();
    }
    $sth = StartDB();
    $auth_data = authDataFromDB($sth, $server_id); 
    $server = GetServerById($sth['get_server_by_id'], $server_id);
    
    /**Определяем заказ или счет */
    $pay_type = '';
    $paysystem_arr = json_decode($auth_data["paysystems_text"],true);

    foreach($paysystem_arr as $paysystem_num => $paysystem_data)
    {
        if ($paysystem_id ==$paysystem_num)
        {
            $pay_type=$paysystem_data['ENTITY_REGISTRY_TYPE'] ;
        } 
    }

    if ($pay_type=='')
    {
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Ошибка! Неизвестный тип оплаты, выпонение остановлено!".PHP_EOL,true),FILE_APPEND);
        echo 'Отсутствует тип оплаты pay_type!';
        die();
    }   

    $secret = $auth_data['pk_secret_seed'];      
    $transaction = GetTransaction($sth["get_transaction"],$transaction_id);// Получаем данные транзакции     

    if ($transaction["order_id"]!=$orderid &&  $transaction["sum"]!=$sum) // проверяем совпадение суммы и номера заказа
    {
        file_put_contents(__DIR__.'/log/'."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Не совпадают параметры закзаза !!! ".$pk_payment_id.PHP_EOL,true),FILE_APPEND);        
        echo "Не совпадают параметры закзаза !!!";
        die();
    }

    $hash = md5($pk_payment_id.$sum.$clientid.$orderid.$secret);
    if ($hash != $sign)//Проверяем совпадение хеша
    {
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . "Повторное подтверждение транзакции ".$transaction_id . PHP_EOL, FILE_APPEND);
        echo "Hash mismatch";
        //echo ' - '.$_POST['clientid'];
        //print_r($_POST);
        //echo $pk_payment_id.$sum.$clientid.$orderid.$secret;
        die();
    }

    if ($transaction["status"]=="paid")// Если статус транзакции paid, то подтверждаем
    {
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . "Повторное подтверждение транзакции ".$transaction_id . PHP_EOL, FILE_APPEND);
        echo "OK ".md5($pk_payment_id.$secret);
        die();
    }

    if ($transaction["status"]=="pending")// Если статус транзакции pending, то меняем на paid
    {
        UpdateTransaction($sth["update_transaction"],$transaction_id,'paid');
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . "Статус транзакции ".$transaction_id ." обновлен на paid". PHP_EOL, FILE_APPEND);
        echo "OK ".md5($pk_payment_id.$secret);
        ob_flush();
        flush();    
    }  
    if ($pay_type=="CRM_INVOICE")
    {
        $invoice_info = CRestBD::call('crm.invoice.get', $auth_data, array("id" => $orderid));

        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Заказ № ".$orderid.PHP_EOL.$invoice_info,true),FILE_APPEND);   
        $invoice_info['result']['STATUS_ID'] = 'P';
        $invoiceUpdate = CRestBD::call('crm.invoice.update', $auth_data, array(
            'id' => $orderid,
            'fields'=> $invoice_info['result']
          ));
             
        if (isset($invoiceUpdate['error']))
        {
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Заказ № ".$orderid.PHP_EOL.$invoiceUpdate,true),FILE_APPEND);
        } else 
        {
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Заказ № ".$orderid.PHP_EOL.$invoiceUpdate,true),FILE_APPEND);  
        }
    } 
    else if ($pay_type="ORDER")
    {
        $PaymentUpdate= CRestBD::call('sale.payment.update', $auth_data, array
        (
            'id'=> $payment_id,
            'fields'=> array
            (
                'paySystemId' =>$paysystem_id,
                'paid'=> 'Y'
            )
        ));
        if (isset($PaymentUpdate['error']))
        {
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Ошибка платежа ".$payment_id.PHP_EOL."Заказ № ".$orderid.PHP_EOL,true),FILE_APPEND);
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", print_r($PaymentUpdate,true),FILE_APPEND);
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", var_dump($PaymentUpdate),FILE_APPEND);
        } else 
        {
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Обновлен платеж ".$payment_id.PHP_EOL."Заказ № ".$orderidы,true),FILE_APPEND);
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", print_r($PaymentUpdate,true),FILE_APPEND);  
        }
    }      


    if (isset($PaymentUpdate['error'],$invoiceUpdate['error']))
    {
        if ($PaymentUpdate['error'] == 'expired_token')// если просроченный токен
        {
            $newAuth = CRestBD::GetNewAuth($auth_data);// получение нового токена

            if (isset($newAuth['error']))
            {
                file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL.$newAuth,true),FILE_APPEND);
                die();// Завершаем скрипт, т.к. не можем обновить авторизацию
            }
            elseif (isset($newAuth['access_token']) && isset($newAuth['refresh_token'])) 
            {
                $auth_data['access_token']     =   $newAuth['access_token'] ;
                $auth_data['refresh_token']    =   $newAuth['refresh_token'];
                $auth_data['expires']          =   $newAuth['expires'];
                TokenUpdate($sth["token_update"],$server_id,$auth_data['access_token'],$auth_data['refresh_token'], $auth_data['expires']);
                if ($pay_type=="CRM_INVOICE")
                {
                    $invoice_info = CRestBD::call('crm.invoice.get', $auth_data, array("id" => $orderid));
                    $invoice_info['result']['STATUS_ID'] = 'P';
                    $invoiceUpdate = CRestBD::call('crm.invoice.update', $auth_data, array(
                        'id' => $orderid,
                        'fields'=> $invoice_info['result']
                      ));
                    if (isset($invoiceUpdate['error']))
                    {
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Заказ № ".$orderid.PHP_EOL.$invoiceUpdate,true),FILE_APPEND);
                    } else 
                    {
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Заказ № ".$orderid.PHP_EOL.$invoiceUpdate,true),FILE_APPEND);  
                    }
                } 
                else if ($pay_type="ORDER")
                {
                    $PaymentUpdate= CRestBD::call('sale.payment.update', $auth_data, array
                    (
                        'id'=> $payment_id,
                        'fields'=> array
                        (
                            'paySystemId' =>$paysystem_id,
                            'paid'=> 'Y'
                        )
                    ));
                    if (isset($PaymentUpdate['error']))
                    {
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Ошибка платежа ".$payment_id.PHP_EOL."Заказ № ".$orderid,true),FILE_APPEND);
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", print_r($PaymentUpdate,true),FILE_APPEND);
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", print_r($PaymentUpdate,true),FILE_APPEND);
                    } else 
                    {
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL.print_r(date('Y-m-d\TH:i:s').PHP_EOL."Обновлен платеж ".$payment_id.PHP_EOL."Заказ № ".$orderid,true),FILE_APPEND);
                        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", print_r($PaymentUpdate,true),FILE_APPEND); 
                    }
                }     

            }
        }
    }
     



?>