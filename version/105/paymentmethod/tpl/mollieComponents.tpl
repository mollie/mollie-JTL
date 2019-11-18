<h3>Zahlungsinformationen</h3>
<div class="form-horizontal">
    <div id="mollieError"></div>
    <div class="form-group">
        <label for="inputEmail3" class="col-sm-2 control-label">{$mollieLang.lbl_cardHolder}</label>
        <div class="col-sm-10">
            <div class="form-control" id="card-holder"></div>
        </div>
    </div>
    <div class="form-group">
        <label for="inputEmail3" class="col-sm-2 control-label">{$mollieLang.lbl_cardNumber}</label>
        <div class="col-sm-10">
            <div class="form-control" id="card-number"></div>
        </div>
    </div>
    <div class="form-group">
        <label for="inputEmail3" class="col-sm-2 control-label">{$mollieLang.lbl_expiryDate}</label>
        <div class="col-sm-10">
            <div class="form-control" id="expiry-date"></div>
        </div>
    </div>
    <div class="form-group">
        <label for="inputEmail3" class="col-sm-2 control-label">{$mollieLang.lbl_varificationCode}</label>
        <div class="col-sm-10">
            <div class="form-control" id="verification-code"></div>
        </div>
    </div>
</div>

<input type="hidden" name="cardToken" id="cardToken"/>

<script src="https://js.mollie.com/v1/mollie.js"></script>
<script>



    var mollie = Mollie('{$profileId}', {
        locale: '{$locale}'{if $testmode}, testMode: true{/if}
    });

    var cardHolder = mollie.createComponent('cardHolder');
    cardHolder.mount('#card-holder');

    var cardNumber = mollie.createComponent('cardNumber');
    cardNumber.mount('#card-number');

    var expiryDate = mollie.createComponent('expiryDate');
    expiryDate.mount('#expiry-date');

    var verificationCode = mollie.createComponent('verificationCode');
    verificationCode.mount('#verification-code');


    var form = document.getElementById("form_payment_extra");

    form.addEventListener('submit', function(e){
        e.preventDefault();
        var errorDiv = document.getElementById("mollieError");
        errorDiv.innerHTML = '';

        mollie.createToken().then(function(result) {
            const { token, error } = result;
            if (error) {
                var alert = document.createElement('div');
                alert.className = 'alert alert-danger';
                alert.id = 'mollieError';
                alert.textContent = error.message;
                errorDiv.append(alert);
            } else {
                // Add token to the form
                const tokenInput = document.getElementById("cardToken");
                tokenInput.value = token;
                // Re-submit form to the server
                form.submit();
            }
        });
    });

</script>