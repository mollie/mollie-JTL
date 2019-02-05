<?php /* open */
	
try {
    require_once __DIR__ . '/../class/Helper.php';
    \ws_mollie\Helper::init();

	switch(\ws_mollie\Helper::getSetting('load_styles')){
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
	if(\ws_mollie\Helper::getSetting('paymentmethod_sync') === 'size2x'){
		$lh = "40px";
	}
	

	pq('head')->append(<<<CSS
	
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
CSS
);

    
} catch (Exception $e) {
    \ws_mollie\Helper::logExc($e);
}