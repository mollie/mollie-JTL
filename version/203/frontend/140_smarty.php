<?php /* open */

use ws_mollie\Helper;
use ws_mollie\Hook\ApplePay;

try {
    require_once __DIR__ . '/../class/Helper.php';
    Helper::init();

    ApplePay::execute(isset($args_arr) ? $args_arr : []);

// TODO STYLES
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

    pq('head')->append(
        <<<HTML
	<style>
	/* MOLLIE CHECKOUT STYLES*/
	#fieldset-payment .form-group > div:hover, #checkout-shipping-payment .form-group > div:hover {
		background-color: #eee;
		color: black;
	}
	$selector label {
		$border
	}
	
	$selector label::after {
		clear: both;
		content: ' ';
		display: block;
	}
	
	$selector label span small {
		line-height: 48px;
	}
	
	$selector label img {
		float: right;
	}
	</style>
HTML
    );

} catch (Exception $e) {
    Helper::logExc($e);
}
