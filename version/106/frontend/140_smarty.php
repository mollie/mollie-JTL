<?php /* open */

use ws_mollie\Helper;

try {
    require_once __DIR__ . '/../class/Helper.php';
    Helper::init();


    if (array_key_exists('mollieStatus', $_REQUEST)) {
        $status = $_REQUEST['mollieStatus'];
        $text = Helper::oPlugin()->oPluginSprachvariableAssoc_arr['error_' . $status];
        pq('#fieldset-payment')->prepend('<div class="alert alert-danger">' . $text . '</div>');
    }
    
    $applePayId = "kPlugin_".Helper::oPlugin()->kPlugin."_mollieapplepay";
pq('body')->append(<<<HTML
<script type="text/javascript" data-eucookie-name="mollie ApplePay Check" data-eucookie-category="required" data-eucookie-description="Prueft ob ApplePay verfuegbar ist.">
// <!--
if($('#{$applePayId}').length){
    if (!window.ApplePaySession || !window.ApplePaySession.canMakePayments()) {
        $('#{$applePayId}').remove();
    }
}
// -->
HTML
);

    switch (Helper::getSetting('load_styles')) {
        case 'Y':
            $selector = '#fieldset-payment [id*="_mollie"]';
            $border = "";
            break;
        case 'A':
            $selector = '#fieldset-payment';
            $border = "border-bottom: 1px solid #ccc;";
            break;
        case 'N':
        default:
            return;
    }

    $lh = "30px";
    if (Helper::getSetting('paymentmethod_sync') === 'size2x') {
        $lh = "40px";
    }

    pq('head')->append(
        <<<HTML
	<style>
	/* MOLLIE CHECKOUT STYLES*/
	#fieldset-payment .form-group > div:hover, #checkout-shipping-payment .form-group > div:hover {
		background-color: #eee;
		color: black;
	}
	
	{$selector} label > span {
		line-height: {$lh};
	}
	{$selector} label {
		{$border}
	}
	{$selector} label img {
		float: right;
	}
	</style>
HTML
    );
} catch (Exception $e) {
    Helper::logExc($e);
}
