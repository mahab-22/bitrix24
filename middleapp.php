<?php
/** Комментарий техподдержки битрикса по коду признака услуга/товар:
 * В массиве за эту информацию отвечает переменная [type] у данного элемента это- 2,
 *  поскольку это услуга. У простых товаров это 1, а у товаров с предложениями это 3. 111*/

require_once('/var/bitrix24_include/crestBD.php');
require_once('/var/bitrix24_include/common.php');
require_once("/var/bitrix24_include/paykeeper.class.php");
//file_put_contents(__DIR__."/log/debug.log", print_r($_POST,true), FILE_APPEND);
//Проверяем на наличие необходимых параметров для платежа
$get = array_change_key_case($_GET,CASE_LOWER);
$post = array_change_key_case($_POST,CASE_LOWER);
//
//print_r($post);
if (isset($get["shop_id"],$get["shop_key"],$post["sum"],$post["orderid"],$post["paymentid"],$post["bx_paysystem_id"])) {
    if($get["shop_key"]=="Vk1bsvH_rOAd1niI8ZJb4e4X")
    {
        file_put_contents(__DIR__."/log/whitemare.log", print_r($_REQUEST,true), FILE_APPEND);
    }
    $server_id = (int)trim($get["shop_id"]);
    $shop_key_response = htmlspecialchars($get["shop_key"]);
    $sum = (int)trim($post["sum"]);
    $orderId = (int)trim($post["orderid"]);
    $paymentId = (int)trim($post["paymentid"]);
    $paysystem = $post["bx_paysystem_id"];
} else {
        file_put_contents(__DIR__."/log/error.log", date('Y-m-d\TH:i:s')."Не хватает необходимых параметров в GET или POST", FILE_APPEND);
        file_put_contents(__DIR__."/log/error.log", print_r($_POST,true), FILE_APPEND);
        file_put_contents(__DIR__."/log/error.log", print_r($_GET,true), FILE_APPEND);
        echo "Не заполнены требуемые для коректного оформления параметры, пожалуйста обратитесь к организации сгенерировавшей счет.".PHP_EOL;
        die(); // Завершаем скрипт, т.к. не хватает параметров
}

//авторизация через БД
$sth = StartDB();
$auth_data = authDataFromDB($sth, $server_id);
$pay_type = '';
$paysystem_arr = json_decode($auth_data["paysystems_text"],true);

foreach($paysystem_arr as $paysystem_num => $paysystem_data)
{
    if ($paysystem ==$paysystem_num)
    {

        $pay_type=$paysystem_data['ENTITY_REGISTRY_TYPE'] ;
    } 
}

if ($pay_type=='')
{

    echo "Ошибка! Неизвестный пустой тип оплаты, выпонение остановлено!";
    file_put_contents(__DIR__."/log/".substr($server["b24_url"],7)."/error.log", print_r($auth_data,true), FILE_APPEND);
    file_put_contents(__DIR__."/log/".substr($server["b24_url"],7)."/error.log", print_r($paysystem,true), FILE_APPEND);
    die();
} 

if ($pay_type=="CRM_INVOICE")
{
    $saleOrderGet = CRestBD::call('crm.invoice.get', $auth_data, array("id" => $orderId));
} 
else if ($pay_type="ORDER")
{
    $saleOrderGet = CRestBD::call('sale.order.get', $auth_data, array("id" => $orderId));
}

if (isset($saleOrderGet['error'])) 
{
    if ($saleOrderGet['error'] == 'expired_token') // если просроченный токен
    {
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", date('Y-m-d\TH:i:s')."Токен просрочен", FILE_APPEND);
        if(isset($server_id)){
            $sth = StartDB();
            $server = GetServerById($sth['get_server_by_id'], $server_id);
        }
        $newAuth = CRestBD::GetNewAuth($auth_data); // получение нового токена
        if (isset($newAuth['error'])) {
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", date('Y-m-d\TH:i:s')."Не можем обновить авторизацию", FILE_APPEND);
            die(); // Завершаем скрипт, т.к. не можем обновить авторизацию
        } elseif (isset($newAuth['access_token']) && isset($newAuth['refresh_token'])) {
            $auth_data['access_token']     =   $newAuth['access_token'];
            $auth_data['refresh_token']    =   $newAuth['refresh_token'];
            $auth_data['expires']          =   $newAuth['expires'];
            TokenUpdate($sth["token_update"], $server_id, $auth_data['access_token'], $auth_data['refresh_token'], $auth_data['expires']);
            file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", date('Y-m-d\TH:i:s')."Токен получен и обновлен", FILE_APPEND);
            if ($pay_type=="CRM_INVOICE")
            {
                $saleOrderGet = CRestBD::call('crm.invoice.get', $auth_data, array("id" => $orderId));
            } 
            else if ($pay_type="ORDER")
            {
                $saleOrderGet = CRestBD::call('sale.order.get', $auth_data, array("id" => $orderId));
            }
            if (isset($saleOrderGet['error'])) {
                file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", date('Y-m-d\TH:i:s')."После обновления авторизации присутствует ошибка", FILE_APPEND);
                die(); // Завершаем скрипт, т.к. после обновления авторизации присутствует ошибка
            }
        }
    }
}
//file_put_contents(__DIR__.'/log/transactions.log', print_r($saleOrderGet,true), FILE_APPEND);//////////////////////////
$server = GetServerById($sth['get_server_by_id'], $server_id);

//print_r($saleOrderGet);
//print_r($server["shop_key"]);
//file_put_contents(__DIR__."/log/error.log", print_r($server["shop_key"] . " - " . $shop_key_response . " ; ",true), FILE_APPEND);
//&& $server["shop_key"] == $shop_key_response
if (isset($saleOrderGet["result"])) {
    file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . print_r($saleOrderGet, true). PHP_EOL, FILE_APPEND);

    $pk_obj = new PaykeeperPayment();
    //ищем данные плательщика

    if ($pay_type=="ORDER")
    {
        $clientArray = $saleOrderGet["result"]["order"]["propertyValues"];
        if ($clientArray[0]["code"] == "COMPANY") 
        {
            $contactClient = infoClient($clientArray, "CONTACT_PERSON");
            $contactClient .=(isset($clientArray[0]["value"]))?'('.$clientArray[0]['value'].')':"";
        } 
        else 
        {
            $contactClient = infoClient($clientArray, "FIO");
			if (is_null($contactClient))
			{
				$contactClient = infoClient($clientArray, "CONTACT_FULL_NAME");
			}
			if (is_null($contactClient))
			{
				$contactClient = infoClient($clientArray, "CONTACT_NAME");
			}
			if (is_null($contactClient))
			{
				$contactClient = infoClient($clientArray, "CONTACT_LAST_NAME");
			}
            if (is_null($contactClient))
			{
				$contactClient = infoClient($clientArray, "NAME");
			}
        }
        if (!isset($contactClient)) 
        {
            $contactClient = "undefined"; //если не получили данные плательщика присваиваем значение undefined
        }
        $emailClient = infoClient($clientArray, "EMAIL");
        if (is_null($emailClient)) $emailClient = infoClient($clientArray, "CONTACT_EMAIL");
        $phoneClient = infoClient($clientArray, "PHONE");
        if (is_null($phoneClient)) $phoneClient = infoClient($clientArray, "CONTACT_PHONE");
        $basketItems = $saleOrderGet["result"]["order"]["basketItems"];
    } 
    elseif ($pay_type=="CRM_INVOICE") 
    {
        $clientArray = $saleOrderGet["result"]["INVOICE_PROPERTIES"];
        if (isset($clientArray["COMPANY"])) 
        {
            $contactClient = $clientArray["COMPANY"];
        } 
        else 
        {
            $contactClient = $clientArray["CONTACT_PERSON"];
        }
        if (!isset($contactClient)) 
        {
            $contactClient = "Имя не задано"; //если не получили данные плательщика присваиваем значение undefined
        }
        $emailClient = $clientArray["EMAIL"];
        if (is_null($emailClient)) $emailClient = infoClient($clientArray, "CONTACT_EMAIL");
        $phoneClient = $clientArray["PHONE"];
        if (is_null($phoneClient)) $phoneClient = infoClient($clientArray, "CONTACT_PHONE");
        $basketItems = $saleOrderGet["result"]["PRODUCT_ROWS"];    
    }
     
    $pk_obj->setOrderParams(
        $sum,                                       //sum
        trim($contactClient),                             //clientid
        $orderId,                                   //orderid
        trim($emailClient),                               //client_email
        trim($phoneClient),                               //client_phone
        $server_id . "|" . $paymentId,              //service_name
        $server["pk_url"],                          //payment form url
        $server["pk_secret_seed"]                   //secret key
    );

    $discountIncluded = 0;
    $item_index = 0;
    foreach ($basketItems as $item) {
        $taxes = array("tax" => "none", "tax_sum" => 0);
        if ($pay_type=="ORDER") #если оплата по сделке ...
        {
            $name = $item["name"];
            $quantity = $item["quantity"];
            $price = abs($item["price"]);
            //&& $_POST["vatProduct"]!=''
            if (isset($_POST["vatProduct"]) && $_POST["vatProduct"]!='')
            {
                $vat_rate = (int)$_POST["vatProduct"];
                $taxes = $pk_obj->setTaxes((float)$vat_rate);
            } 
            else 
            {
                if ($item["vatIncluded"] == "Y") 
                {
                    $pk_obj->setUseTaxes(1);
                    $vat_rate = ($item["vatRate"] * 100);
                    $taxes = $pk_obj->setTaxes((float)$vat_rate);
                }
            }
            if ($item["discountValue"] > 0) 
            {
                $discountIncluded = 1;
            }
        }
        elseif ($pay_type=="CRM_INVOICE")  #если оплата по счету ...
        {
            $name = $item["PRODUCT_NAME"];
            $quantity = $item["QUANTITY"];
            $price = abs($item["PRICE"]);
            if ($item["VAT_INCLUDED"] == "Y") 
            {
                $pk_obj->setUseTaxes(1);
                $vat_rate = ($item["VAT_RATE"] * 100);
                $taxes = $pk_obj->setTaxes((float)$vat_rate);
            }
            if ($item["DISCOUNT_PRICE"] > 0) 
            {
                $discountIncluded = 1;
            }
        }
        if ($quantity == 1 && $pk_obj->single_item_index < 0)
                $pk_obj->single_item_index = $item_index;
        if ($quantity > 1 && $pk_obj->more_then_one_item_index < 0)
                $pk_obj->more_then_one_item_index = $item_index;
        $line_sum = $price * $quantity;

        $pk_obj->updateFiscalCart($pk_obj->getPaymentFormType(), $name, $price, $quantity, $line_sum, $taxes["tax"]);
        $item_index++;
    }

    if (isset($saleOrderGet["result"]["order"]["shipments"])) {
        $delivery_name = $saleOrderGet["result"]["order"]["shipments"][0]["deliveryName"];
        $delivery_price = $saleOrderGet["result"]["order"]["shipments"][0]["basePriceDelivery"];
        $pk_obj->setShippingPrice($delivery_price);
        if (
            !$pk_obj->checkDeliveryIncluded($pk_obj->getShippingPrice(), $delivery_name)
            && $pk_obj->getShippingPrice() > 0
        ) {
            if (isset($_POST["vatDelivery"]) && $_POST["vatDelivery"]!='') {
                $delivery_tax_rate = (int)$_POST["vatDelivery"];
            } else {
                $delivery_tax_rate = 0;
            }
            if(substr($server["b24_url"],8)=='oooxch.bitrix24.ru')
            {
                $delivery_tax_rate = 20;
            }
            $delivery_taxes = $pk_obj->setTaxes($delivery_tax_rate);
            $pk_obj->setUseDelivery();
            $pk_obj->updateFiscalCart($pk_obj->getPaymentFormType(), $delivery_name, $delivery_price, 1, $delivery_price, $delivery_taxes["tax"]);
            $pk_obj->delivery_index = count($pk_obj->getFiscalCart()) - 1;
        }
    }
    // echo "<pre>";
    // print_r($saleOrderGet);
    // print_r($pk_obj);
    // die();
    $pk_obj->setDiscounts($discountIncluded);
    
    $pk_obj->correctPrecision();
    /*
    echo "<pre>";
    var_dump($pk_obj);
    var_dump($sum);
    die();
    echo "</pre>";
    */

    $to_hash = number_format($pk_obj->getOrderTotal(), 2, ".", "") .
        $pk_obj->getOrderParams("clientid") .
        $pk_obj->getOrderParams("orderid") .
        $pk_obj->getOrderParams("service_name") .
        $pk_obj->getOrderParams("client_email") .
        $pk_obj->getOrderParams("client_phone") .
        $pk_obj->getOrderParams("secret_key");
    $sign = hash('sha256', $to_hash);

    $transaction_id = AddTransaction(
        $sth["add_transaction"],
        $server_id,
        $pk_obj->getOrderParams("orderid"),
        $pk_obj->getOrderParams("client_phone"),
        $pk_obj->getOrderParams("client_email"),
        $pk_obj->getOrderTotal(),
        htmlentities($pk_obj->getFiscalCartEncoded(), ENT_QUOTES)
    );

    if ($transaction_id > 0) {
        $pk_obj->order_params["service_name"] = $pk_obj->order_params["service_name"] . "|" . $transaction_id . "|" . $_POST["BX_PAYSYSTEM_ID"];
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", date('Y-m-d\TH:i:s').print_r(orderLog($pk_obj, $server_id, $server["b24_url"], $transaction_id), true). PHP_EOL, FILE_APPEND);
        echo $pk_obj->getDefaultPaymentForm($sign);
    } else {
        file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/transactions.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . "Не удалось записать платеж в БД " . print_r($pk_obj->getOrderParams("orderid"), true). PHP_EOL, FILE_APPEND);
        echo "No transaction added. Contact Support.";
        die(); // Завершаем скрипт, не удалось записать платеж в БД
    }
} else {
    file_put_contents(__DIR__.'/log/'.substr($server["b24_url"],7)."/error.log", PHP_EOL . date('Y-m-d\TH:i:s') . PHP_EOL . "Запрос API к Б24 - ошибка " . print_r($saleOrderGet, true). PHP_EOL, FILE_APPEND);
    // echo "<pre>";
    // print_r($server["shop_key"]);
    // echo '<br>';
    // print_r($shop_key_response);
    // print_r($saleOrderGet);
    die(); // Завершаем скрипт, Запрос API к Б24 - ошибка
}



function pkobj_from_invoice ($invoiceArray)
{
    $pk_obj = new PaykeeperPayment();
    //ищем данные плательщика
    $clientArray = $saleOrderGet["INVOICE_PROPERTIES"];
    if (isset($clientArray["COMPANY"])) {
        $contactClient = $clientArray["COMPANY"];
    } else {
        $contactClient = $clientArray["CONTACT_PERSON"];
    }
    if (!isset($contactClient)) {
        $contactClient = "Имя не задано"; //если не получили данные плательщика присваиваем значение undefined
    }
    $emailClient = $clientArray["EMAIL"];
    $phoneClient = $clientArray["PHONE"];

    
    $pk_obj->setOrderParams(
        $sum,                             //sum
        $contactClient,                             //clientid
        $orderId,                          //orderid
        $emailClient,                               //client_email
        $phoneClient,                               //client_phone
        $server_id . "|" . $paymentId,                  //service_name
        $server["pk_url"],                            //payment form url
        $server["pk_secret_seed"]                     //secret key
    );

    $basketItems = $saleOrderGet["result"]["PRODUCT_ROWS"];
    $discountIncluded = 0;
    $item_index = 0;
    foreach ($basketItems as $item) {
        $taxes = array("tax" => "none", "tax_sum" => 0);
        $name = $item["PRODUCT_NAME"];
        $quantity = $item["QUANTITY"];
        if ($quantity == 1 && $pk_obj->single_item_index < 0)
                $pk_obj->single_item_index = $item_index;
            if ($quantity > 1 && $pk_obj->more_then_one_item_index < 0)
                $pk_obj->more_then_one_item_index = $item_index;
        $price = $item["PRICE"];
        $sum = $price * $quantity;
        if (isset($_POST["vatProduct"])) {
            $vat_rate = (int)$_POST["vatProduct"];
            $taxes = $pk_obj->setTaxes((float)$vat_rate);
        } else {
            if ($item["VAT_INCLUDED"] == "Y") {
                $vat_rate = ($item["VAT_RATE"] * 100);
                $taxes = $pk_obj->setTaxes((float)$vat_rate);
            }
        }
        if ($item["discountPrice"] > 0) {
            $discountIncluded = 1;
        }
        $pk_obj->updateFiscalCart($pk_obj->getPaymentFormType(), $name, $price, $quantity, $sum, $taxes["tax"]);
        $item_index++;
    }



    // if (isset($saleOrderGet["result"]["order"]["shipments"])) {
    //     $delivery_name = $saleOrderGet["result"]["order"]["shipments"][0]["deliveryName"];
    //     $delivery_price = $saleOrderGet["result"]["order"]["shipments"][0]["basePriceDelivery"];
    //     $pk_obj->setShippingPrice($delivery_price);
    //     if (
    //         !$pk_obj->checkDeliveryIncluded($pk_obj->getShippingPrice(), $delivery_name)
    //         && $pk_obj->getShippingPrice() > 0
    //     ) {
    //         if (isset($_POST["vatDelivery"])) {
    //             $delivery_tax_rate = (int)$_POST["vatDelivery"];
    //         } else {
    //             $delivery_tax_rate = 0;
    //         }
    //         $delivery_taxes = ((int)$delivery_tax_rate == 0) ? array("tax" => "none", "tax_sum" => 0) : $pk_obj->setTaxes($delivery_tax_rate);
    //         $pk_obj->setUseDelivery();
    //         $pk_obj->updateFiscalCart($pk_obj->getPaymentFormType(), $delivery_name, $delivery_price, 1, $delivery_price, $delivery_taxes["tax"]);
    //         $pk_obj->delivery_index = count($pk_obj->getFiscalCart()) - 1;
    //     }
    // }
    
    return $pk_obj;
}
function infoClient($clientArray, $attribute)
{
    foreach ($clientArray as $array) {
        if ($array['code'] == $attribute) {
            return $array["value"];
        }
    }
}
function orderLog(PaykeeperPayment $pk_obj, $server_id, $b24_url, $transaction_id)
{
    return array(
        "B24_url" => $b24_url,
        "server_id" => $server_id,
        "transaction_id" => $transaction_id,
        "sum" => $pk_obj->getOrderTotal(),
        "order_id" => $pk_obj->getOrderParams("orderid"),
        "client_phone" => $pk_obj->getOrderParams("client_phone"),
        "client_email" => $pk_obj->getOrderParams("client_email"),
        "cart" => htmlentities($pk_obj->getFiscalCartEncoded(), ENT_QUOTES),
    );
}
