{if isset($oMollieException)}
    <div class="alert alert-danger">
        {$oMollieException->getMessage()}
    </div>
{/if}

{if isset($redirect) && $redirect != ''}
    <div class="row">
        <div class="col-md-4 col-lg-3 col-xl-2">
            <button type="link" href="{$redirect}" class="btn btn-primary btn-lg block">
                {lang key='payNow' section='global'}
            </button>
        </div>
    </div>
    {if $checkoutMode == 'D'}
        <meta http-equiv="refresh" content="{$smarty.const.MOLLIE_REDIRECT_DELAY}; URL={$redirect}">
    {/if}
{/if}

