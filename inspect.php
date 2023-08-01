<?php
  require_once ('/var/bitrix24_include/crestBD.php');
  require_once ('/var/bitrix24_include/common.php');
  require_once("/var/bitrix24_include/buildHandler.php");
  require_once("/var/bitrix24_include/buildPayment.php");

$server_id = 238;
$sth = StartDB();
$auth_data = authDataFromDB($sth, $server_id);
$payment_id =36;
$paysystem_id =22;
$response = CRestBD::call('sale.persontype.list', $auth_data);
//$response = CRestBD::call('sale.paysystem.handler.list', $auth_data);         // список обработчиков
//$response = CRestBD::call('sale.paysystem.list', $auth_data);                // список платеэных систем
//$response = CRestBD::call('sale.persontype.list', $auth_data);        //
//$response = CRestBD::call('crm.persontype.list', $auth_data);
//$response = CRestBD::call('sale.paysystem.list', $auth_data);  
//$response = CRestBD::call('sale.paysystem.handler.list', $auth_data);  
//$response = CRestBD::call('sale.paysystem.handler.delete', $auth_data, array("id" => 411));  
//$response = CRestBD::call('sale.paysystem.delete',$auth_data, array("id" => 89));
// print_r($auth_data);
//$response = CRestBD::call('sale.payment.update', $auth_data, array
// (
//     'id'=> $payment_id,
//     'fields'=> array
//     (
//         'paySystemId' =>$paysystem_id,
//         'paid'=> 'Y'
//     )
// ));
//================= получение типов плательщиков CRM ==================
//$response = CRestBD::call('crm.persontype.list', $auth_data);
//=====================================================================
//================= получение типов плательщиков SALE ==================
//$response = CRestBD::call('sale.persontype.list', $auth_data);
//=====================================================================
//=================Создание ПС ========================================
// $middleapp          = "https://bitrix24.paykeeper.ru/middleapp.php?shop_id=".$auth_data["server_id"]."&shop_key=".$auth_data["shop_key"];
// $paySystemName      = "Paykeeper";
// $handlerName        = "Paykeeper";
// $handlerCode        = "Paykeeper";
// $paysystem_object = buildPayment($paySystemName,$handlerCode,10,'ORDER');
// $response = CRestBD::call('sale.paysystem.add', $auth_data, $paysystem_object); 
//=====================================================================
if (isset($response['error']))
{
  if ($response['error'] == 'expired_token') // если просроченный токен
  {
    echo "авторизация отсутствует!";
    if(isset($server_id))
    {
      $server = GetServerById($sth['get_server_by_id'], $server_id);
    }
    $newAuth = CRestBD::GetNewAuth($auth_data); // получение нового токена
    if (isset($newAuth['error'])) 
    {
      echo "Не могу получить авторизацию!";
      die(); // Завершаем скрипт, т.к. не можем обновить авторизацию
    } 
    elseif (isset($newAuth['access_token']) && isset($newAuth['refresh_token'])) 
    {
      $auth_data['access_token']     =   $newAuth['access_token'];
      $auth_data['refresh_token']    =   $newAuth['refresh_token'];
      $auth_data['expires']          =   $newAuth['expires'];
      TokenUpdate($sth["token_update"], $server_id, $auth_data['access_token'], $auth_data['refresh_token'], $auth_data['expires']);
      echo "авторизация получена!";
    }
  }
}


echo '<pre>'; 
print_r($response);
//print_r($auth_data);
echo '</pre>';
?>