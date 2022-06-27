{if isset($oMollieException)}
    <div class="alert alert-danger">
        {$oMollieException->getMessage()}
    </div>
    <div class="row">
        <div class="col-md-4 col-lg-3 col-xl-2">
            <a href="{$tryAgain}" class="btn btn-primary btn-lg block">
                {lang key='payNow' section='global'}
            </a>
        </div>
    </div>
{/if}

{if isset($redirect) && $redirect != ''}
    <div class="row">
        <div class="col-md-4 col-lg-3 col-xl-2">
            <a href="{$redirect}" class="btn btn-primary btn-lg block">
                {lang key='payNow' section='global'}
            </a>
        </div>
    </div>
    {if $checkoutMode == 'D'}
        <meta http-equiv="refresh" content="{$smarty.const.MOLLIE_REDIRECT_DELAY}; URL={$redirect}">
    {/if}
{/if}

