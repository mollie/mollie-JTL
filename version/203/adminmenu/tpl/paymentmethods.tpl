<h3>Account Status</h3>
<table style="width: 100%">
    <tr>
        <th>Mode:</th>
        <td>{$profile->mode}</td>
        <th>Status:</th>
        <td>{$profile->status}</td>
        {if $profile->review}
            <th>Review:</th>
            <td>{$profile->review->status}</td>
        {/if}
        {if $profile->_links->checkoutPreviewUrl->href}
            <td width="15%" style="text-align: right;">
                <a class="btn btn-success" href="{$profile->_links->checkoutPreviewUrl->href}" target="_blank">Checkout
                    Preview <i class="fa fa-external-link"></i></a>
            </td>
        {/if}
        <td width="15%" style="text-align: right;">
            <a class="btn btn-info" href="https://www.mollie.com/dashboard" target="_blank">Mollie Dashboard <i
                        class="fa fa-external-link"></i></a>
        </td>
    </tr>
</table>
<hr/>
<div class="row">
    <form action="plugin.php" method="get">
        <input type="hidden" name="kPlugin" value="{$oPlugin->kPlugin}">
        <input type="hidden" name="za" value="1">
        <div class="col-xs-12 col-sm-3">
            <label for="cLocale">Locale:</label>
            <select name="locale" id="cLocale">
                {foreach from=$locales item=locale}
                    <option {if "locale"|array_key_exists:$smarty.get && $smarty.get.locale === $locale}selected="selected"{/if}
                            value="{$locale}">{$locale}</option>
                {/foreach}
            </select>

        </div>
        <div class="col-xs-12 col-sm-3">
            <label for="cCurrency">Währung:</label>
            <select name="currency" id="cCurrency">
                {foreach from=$currencies item=currency key=key}
                    <option {if "currency"|array_key_exists:$smarty.get && $smarty.get.currency === $key}selected="selected"{/if}
                            value="{$key}">{$currency}</option>
                {/foreach}
            </select>
        </div>
        <div class="col-xs-12 col-sm-3">
            <label for="cAmount">Betrag:</label>
            <input type="number"
                   value="{if "amount"|array_key_exists:$smarty.get && $smarty.get.amount}{$smarty.get.amount}{else}10{/if}"
                   name="amount" id="cAmount">
        </div>
        <div class="col-xs-12 col-sm-3" style="text-align: right">
            <input id="cActive" type="checkbox" value="1" name="active"
                   {if "active"|array_key_exists:$smarty.get}checked="checked"{/if}><label for="cActive">Nur aktive
                ZA</label>
            <button class="btn btn-primary" type="submit">Test API</button>
            <a class="btn btn-info" href="plugin.php?kPlugin={$oPlugin->kPlugin}&za=1">reset</a>
        </div>
    </form>
</div>
<hr/>
{if $allMethods && $allMethods|count}
    <table class="table table-striped table-condensed">
        <thead>
        <tr>
            <th>Bild</th>
            <th>Name / ID</th>
            <th>Info</th>
            <th>Preise</th>
            <th>Limits</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$allMethods item=method}
            <tr>
                <td><img alt="{$method->mollie->description|utf8_decode}"
                         title="{$method->mollie->description|utf8_decode}"
                         src="{$method->mollie->image->svg}" height="50"/></td>
                <td>
                    {if $method->mollie->status === 'activated'}
                        <span class="fa fa-check" style="color: green; cursor:help;"
                              title="Mollie Status: aktiv"></span>
                    {else}
                        <span class="fa fa-times" style="color: red; cursor:help;"
                              title="Mollie Status: inaktiv"></span>
                    {/if}
                    <b>{$method->mollie->description|utf8_decode}</b><br/>
                    <code>{$method->mollie->id}</code>
                </td>
                <td>
                    {if $method->shop && $method->oClass}
                        {if intval($method->shop->nWaehrendBestellung) === 1 && !$method->allowPreOrder}
                            <div style="color: red">Zahlung <b>VOR</b> Bestellabschluss nicht unterstützt!</div>
                        {else}
                            <div>
                                <b>Bestellabschluss:</b>
                                {if intval($method->shop->nWaehrendBestellung) === 1}
                                    <span class="label label-info">VOR Zahlung</span>
                                {else}
                                    <span class="label label-info">NACH Zahlung</span>
                                {/if}
                            </div>
                        {/if}

                        {if intval($settings.autoStorno) > 0}
                            <div>
                                <b>Unbez. Bestellung stornieren:</b>
                                {if $method->allowAutoStorno}
                                    <div class="label label-success">auto</div>
                                {else}
                                    <div class="label label-warning">manual</div>
                                {/if}
                            </div>
                        {/if}
                        <div>
                            <b>Gültigkeit:</b>
                            <span class="label label-info">{$method->maxExpiryDays} Tage</span>
                        </div>
                    {else}
                        <b>Derzeit nicht unterstützt.</b>
                    {/if}
                </td>
                <td>
                    <ul>
                        {foreach from=$method->mollie->pricing item=price}
                            <li>
                                <b>{$price->description|utf8_decode}</b>: {$price->fixed->value} {$price->fixed->currency}
                                {if $price->variable > 0.0}
                                    + {$price->variable}%
                                {/if}
                            </li>
                        {/foreach}
                    </ul>
                </td>
                <td>
                    Min: {if $method->mollie->minimumAmount}{$method->mollie->minimumAmount->value} {$method->mollie->minimumAmount->currency}{else}n/a{/if}
                    <br>
                    Max: {if $method->mollie->maximumAmount}{$method->mollie->maximumAmount->value} {$method->mollie->maximumAmount->currency}{else}n/a{/if}
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
{else}
    <div class="alert alert-warning">Es konnten keine Methoden abgerufen werden.</div>
{/if}