<h3>{$mollieLang.cctitle}</h3>

<div class="form-horizontal">

    <div id="mollieError"></div>

    {if $token !== false}

    <div class="row clear-mollie-components" style="margin: 25px">
        <div class="col order-1 col-md-6 col-12">
            <b>{$mollieLang.clearDescr}</b>
        </div>
        <div class="col order-2 col-md-4 col-12">
            <button class="btn btn-block btn-primary" type="submit" name="clear" value="1"
                    id="clearMollieComponents">{$mollieLang.clearButton}</button>
        </div>
    </div>

    {if $trustBadge}
        <div class="text-center">
            <img src="{$trustBadge}" style="height: 90px; max-width: 100%" alt="PCI-DSS SAQ-A compliant"
                 title="PCI-DSS SAQ-A compliant"/>
        </div>
    {/if}

    {else}


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
        <label for="inputEmail3" class="col-sm-2 control-label">
            <a onclick="$('.cvchint').fadeToggle(); return false;"
               style="background: #333;cursor: help; border-radius: 50%;color: white;padding: 0 5px;font-size: 10px;margin-right: 5px;">?</a>{$mollieLang.lbl_varificationCode}
        </label>
        <div class="col-sm-10">
            <div class="form-control" id="verification-code"></div>

            <div class="cvchint" style="display: none; padding: 15px">
                <svg xmlns="http://www.w3.org/2000/svg" width="60" height="40" style="float: left; margin: 10px;">
                    <path d="M 6 40 C 2.686 40 0 37.314 0 34 L 0 13 L 60 13 L 60 34 C 60 37.314 57.314 40 54 40 Z M 0 6 C 0 2.686 2.686 0 6 0 L 54 0 C 57.314 0 60 2.686 60 6 L 60 8 L 0 8 Z"
                          fill="#999"></path>
                    <path d="M 46 16 C 51.523 16 56 20.477 56 26 C 56 31.523 51.523 36 46 36 C 40.477 36 36 31.523 36 26 C 36 20.477 40.477 16 46 16 Z"
                          fill="#fff"></path>
                    <path d="M 40.588 24.042 L 39 25.198 L 39 24.372 L 40.664 23.178 L 41.439 23.178 L 41.439 29.441 L 40.652 29.441 L 40.652 24.042 Z M 44.031 24.944 L 43.281 24.944 C 43.281 23.826 44.12 23.038 45.289 23.038 C 46.419 23.038 47.27 23.8 47.27 24.791 C 47.27 25.439 46.978 25.947 45.949 27.053 L 44.437 28.666 L 44.437 28.73 L 47.372 28.73 L 47.372 29.428 L 43.319 29.428 L 43.319 28.895 L 45.492 26.532 C 46.305 25.655 46.495 25.312 46.495 24.817 C 46.495 24.194 45.962 23.711 45.276 23.711 C 44.526 23.699 44.031 24.194 44.031 24.944 Z M 52.2 27.726 C 52.2 27.002 51.653 26.544 50.764 26.544 L 49.964 26.544 L 49.964 25.871 L 50.726 25.871 C 51.437 25.871 51.946 25.414 51.946 24.753 C 51.946 24.118 51.463 23.686 50.739 23.686 C 50.015 23.686 49.544 24.08 49.481 24.753 L 48.719 24.753 C 48.795 23.673 49.57 23 50.764 23 C 51.857 23 52.733 23.724 52.733 24.639 C 52.733 25.388 52.327 25.935 51.641 26.1 L 51.641 26.163 C 52.492 26.303 53 26.887 53 27.751 C 53 28.78 52.034 29.593 50.789 29.593 C 49.519 29.593 48.642 28.882 48.579 27.815 L 49.328 27.815 C 49.392 28.488 49.964 28.92 50.764 28.92 C 51.615 28.907 52.2 28.412 52.2 27.726 Z"
                          fill="#232323"></path>
                </svg>
                <b>{$mollieLang.cvchint_1}</b>
                <p>{$mollieLang.cvchint_2}</p>
            </div>
        </div>
    </div>

    {if $trustBadge}
        <div class="text-center">
            <img src="{$trustBadge}" style="height: 90px; max-width: 100%" alt="PCI-DSS SAQ-A compliant"
                 title="PCI-DSS SAQ-A compliant"/>
        </div>
    {/if}
    {if $components == 'S'}
        <div class="skip-mollie-components" style="margin: 10px; text-align: right">
            <a href="#" id="skipMollieComponents">{$mollieLang.skipComponentsLink}</a>
        </div>
    {/if}
</div>

{/if}

<input type="hidden" name="cardToken" id="cardToken" value="{if $token}{$token}{/if}"/>

<script src="https://js.mollie.com/v1/mollie.js"></script>

<script>
    // <!--
    const skipLink = document.getElementById('skipMollieComponents');
    const cardToken = document.getElementById('cardToken');
    const form = document.getElementById("form_payment_extra");

    const errorMessage = {if isset($errorMessage)}{$errorMessage}{else}null{/if};
    const mollie = Mollie('{$profileId}', {
        locale: '{$locale}'{if $testMode}, testMode: true{/if}
    });

    const cardHolder = mollie.createComponent('cardHolder');
    cardHolder.mount('#card-holder');

    const cardNumber = mollie.createComponent('cardNumber');
    cardNumber.mount('#card-number');

    const expiryDate = mollie.createComponent('expiryDate');
    expiryDate.mount('#expiry-date');

    const verificationCode = mollie.createComponent('verificationCode');
    verificationCode.mount('#verification-code');

    skipLink.addEventListener('click', function (e) {
        cardToken.setAttribute('name', 'skip');
        cardToken.setAttribute('value', "1");
        e.preventDefault();
        form.submit();
        return false;
    });

    form.addEventListener('submit', function (e) {

        if (cardToken.getAttribute('name') === 'skip') {
            return true;
        }

        e.preventDefault();
        const errorDiv = document.getElementById("mollieError");
        errorDiv.innerHTML = '';

        mollie.createToken().then(function (result) {
            if (result.error) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-danger';
                alert.id = 'mollieErrorContent';
                alert.textContent = errorMessage ? errorMessage : result.error.message;
                errorDiv.append(alert);
            } else {
                // Add token to the form
                const tokenInput = document.getElementById("cardToken");
                tokenInput.value = result.token;
                // Re-submit form to the server
                form.submit();
            }
        });
    });
    // -->
</script>