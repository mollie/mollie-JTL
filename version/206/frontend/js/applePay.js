// <!--
// noinspection JSUnresolvedVariable,JSUnresolvedFunction

if (window.MOLLIE_APPLEPAY_CHECK_URL) {
    if (window.jQuery) {
        $(function () {
            const setApplePayStatus = function (status) {

                $.ajax(window.MOLLIE_APPLEPAY_CHECK_URL, {
                    method: 'POST',
                    contentType: "application/x-www-form-urlencoded; charset=UTF-8",
                    data: {
                        available: status
                    }
                });

            }
            setApplePayStatus(window.ApplePaySession && window.ApplePaySession.canMakePayments() ? 1 : 0);
        });
    }
}
// -->
