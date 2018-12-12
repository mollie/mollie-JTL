<?php

require_once __DIR__ . '/../class/Helper.php';
try {
    if (!\ws_mollie\Helper::init()) {
    //    return;
    }
    
    if(strpos($_SERVER['PHP_SELF'], 'bestellabschluss') === false){
        return;
    }
    
    if(array_key_exists('mollie', $_REQUEST)){
        $payment = \Shop::DB()->executeQueryPrepared("SELECT * FROM " . \ws_mollie\Model\Payment::TABLE . " WHERE cHash = :cHash", [':cHash' => $_REQUEST['mollie']], 1);
        if((int)$payment->kBestellung){
            $bestellid = \Shop::DB()->executeQueryPrepared("SELECT * FROM tbestellid WHERE kBestellung = :kBestellung", [':kBestellung' => $payment->kBestellung], 1);
            if($bestellid){
                header('Location: ' . SHop::getURL() . '/bestellabschluss.php?i='.$bestellid->cId);
                exit();
            }
        }
    }
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}