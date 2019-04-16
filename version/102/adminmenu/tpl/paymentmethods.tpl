<h3>Account Status</h3>
<table style="width: 100%">
    <tr>
        <th>Mode:</th>
        <td>{$profile->mode}</td>
        <th>Status:</th>
        <td>{$profile->status}</td>
        <th>Review:</th>
        <td>{$profile->review->status}</td>
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
                    <option {if $smarty.get.locale === $locale}selected="selected"{/if}
                            value="{$locale}">{$locale}</option>
                {/foreach}
            </select>

        </div>
        <div class="col-xs-12 col-sm-3">
            <label for="cCurrency">Währung:</label>
            <select name="currency" id="cCurrency">
                {foreach from=$currencies item=currency key=key}
                    <option {if $smarty.get.currency === $key}selected="selected"{/if}
                            value="{$key}">{$currency}</option>
                {/foreach}
            </select>
        </div>
        <div class="col-xs-12 col-sm-3">
            <label for="cAmount">Betrag:</label>
            <input type="number" value="{$smarty.get.amount}" name="amount" id="cAmount">
        </div>
        <div class="col-xs-12 col-sm-3">
            <input id="cActive" type="checkbox" value="1" name="active"
                   {if $smarty.get.active}checked="checked"{/if}><label for="cActive">Nur aktive ZA</label>
            <button class="btn btn-primary" type="submit">senden</button>
            <a class="btn btn-info" href="plugin.php?kPlugin={$oPlugin->kPlugin}&za=1">reset</a>
        </div>
    </form>
</div>


{if $allMethods && $allMethods|count}
    <table class="table table-striped table-condensed">
        <thead>
        <tr>
            <th>Bild</th>
            <th>ID</th>
            <th>Name</th>
            <th>Preise</th>
            <th>Infos</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$allMethods item=method}
            <tr>
                <td><img alt="{$method->description|utf8_decode}" title="{$method->description|utf8_decode}"
                         src="{$method->image->svg}" height="50"/></td>
                <td>{$method->id}</td>
                <td>{$method->description|utf8_decode}</td>
                <td>
                    <ul>
                        {foreach from=$method->pricing item=price}
                            <li>{$price->description|utf8_decode}: {$price->fixed->value} {$price->fixed->currency}
                                {if $price->variable > 0.0}
                                    + {$price->variable}%
                                {/if}
                            </li>
                        {/foreach}
                    </ul>
                </td>
                <td>
                    Min: {if $method->minimumAmount}{$method->minimumAmount->value} {$method->minimumAmount->currency}{else}n/a{/if}
                    <br>
                    Max: {if $method->maximumAmount}{$method->maximumAmount->value} {$method->maximumAmount->currency}{else}n/a{/if}
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
{else}
    <div class="alert alert-warning">Es konnten keine Methoden abgerufen werden.</div>
{/if}