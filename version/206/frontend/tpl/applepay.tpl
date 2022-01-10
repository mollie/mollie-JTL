<!--suppress JSUnresolvedVariable, JSUnresolvedFunction -->
<script type="application/javascript" defer>
    // <!--
    if (window.jQuery) {
        $(function () {
            const setApplePayStatus = function (status) {
                $.ajax({$applePayCheckURL}, {
                    method: 'POST',
                    contentType: "application/x-www-form-urlencoded; charset=UTF-8",
                    data: {
                        available: status
                    }
                });
            }
            setApplePayStatus(window.ApplePaySession && window.ApplePaySession.canMakePayments() ? 1 : 0);
        });
    } else if (window.console) {
        console.warn('jQuery not loaded as yet, ApplePay not available!');
    }
    // -->
</script>
