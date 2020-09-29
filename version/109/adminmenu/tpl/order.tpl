<!--suppress HtmlUnknownTarget -->
<h2>
    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}">&laquo;</a>
    Bestellung: {$oBestellung->cBestellNr} -
    {if $oBestellung->cStatus|intval == 1}
        <span class="label label-info">OFFEN</span>
    {elseif $oBestellung->cStatus|intval == 2}
        <span class="label label-info">IN BEARBEITUNG</span>
    {elseif $oBestellung->cStatus|intval == 3}
        <span class="label label-success">BEZAHLT</span>
    {elseif $oBestellung->cStatus|intval == 4}
        <span class="label label-success">VERSANDT</span>
    {elseif $oBestellung->cStatus|intval == 5}
        <span class="label label-warning">TEILVERSANDT</span>
    {elseif $oBestellung->cStatus|intval == -1}
        <span class="label label-danger">STORNO</span>
    {else}
        <span class="label label-danger">n/a</span>
    {/if}
</h2>

{ws_mollie\Helper::showAlerts('orders')}

<table class="table table-condensed" style="width: 100%">
    <tr>
        <th>Mollie ID:</th>
        <td>{$payment->kID}</td>
        <th>Mode:</th>
        <td>{$order->mode}</td>
        <th>Status:</th>
        <td>
            {$order->status}
            {if $order->amountRefunded && $order->amountRefunded->value == $order->amount->value}
                <strong style="color: red">(total refund)</strong>
            {elseif $order->amountRefunded && $order->amountRefunded->value > 0}
                <strong style="color: red">(partly refund)</strong>
            {/if}
        </td>
    </tr>

    <tr>
        <th>Betrag:</th>
        <td>{$order->amount->value|number_format:2:',':''} {$order->amount->currency}</td>
        <th>Captured:</th>
        <td>{if $order->amountCaptured}{$order->amountCaptured->value|number_format:2:',':''} {$order->amountCaptured->currency}{else}-{/if}</td>
        <th>Refunded:</th>
        <td>{if $order->amountRefunded}<strong
                    style="color: red">{$order->amountRefunded->value|number_format:2:',':''} {$order->amountRefunded->currency}</strong>{else}-{/if}
        </td>
    </tr>

    <tr>
        <th>Method:</th>
        <td>{$order->method}</td>
        <th>Locale:</th>
        <td>{$order->locale}</td>
        <th>Erstellt:</th>
        <td>{"d. M Y H:i:s"|date:($order->createdAt|strtotime)}</td>
    </tr>

    <tr>
        <th>Kunde:</th>
        <td>
            {if $order->billingAddress->organizationName}{$order->billingAddress->organizationName}{else}{$order->billingAddress->title} {$order->billingAddress->givenName} {$order->billingAddress->familyName}{/if}
        </td>
        <th>Zahlungslink:</th>
        <td colspan="3">
            <a href="{$payment->cCheckoutURL}" target="_blank">{$payment->cCheckoutURL}</a>
        </td>

    </tr>
</table>
{if $order->payments()->count > 0}
    <h4>Zahlungen</h4>
    <table class="table table-striped table-condensed">
        <thead>
        <tr>
            <th>ID</th>
            <th>Status</th>
            <th>Methode</th>
            <th>Amount</th>
            <th>Settlement</th>
            <th>Refunded</th>
            <th>Remaining</th>
            <th>Details</th>
        </tr>
        </thead>
        {foreach from=$order->payments() item=payment}
            <tr>
                <td>{$payment->id}</td>
                <td>{$payment->status}</td>
                <td>{$payment->method}</td>
                <td>{$payment->amount->value} {$payment->amount->currency}</td>
                <td>
                    {if $payment->settlementAmount}
                        {$payment->settlementAmount->value} {$payment->settlementAmount->currency}
                    {else}-{/if}
                </td>
                <td>
                    {if $payment->amountRefunded}
                        {$payment->amountRefunded->value} {$payment->amountRefunded->currency}
                    {else}-{/if}
                </td>
                <td>
                    {if $payment->amountRemaining}
                        {$payment->amountRemaining->value} {$payment->amountRemaining->currency}
                    {else}-{/if}
                </td>
                <td>
                    <ul>
                        {foreach from=$payment->details item=value key=key}
                            <li><b>{$key}:</b> {if $value|is_scalar}{$value}{else}{$value|json_encode}{/if}</li>
                        {/foreach}
                    </ul>
                </td>
            </tr>
        {/foreach}
    </table>
{/if}


<h4>Positionen:</h4>

<div style="float: right">
    {if ($order->status === 'authorized' || $order->status === 'shipping') && $oBestellung->cStatus|intval >= 3}
        <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=capture&id={$order->id}"
           onclick="return confirm('Bestellung wird bei Mollie als versandt markiert. Zahlung wirklich erfassen?');"
           class="btn btn-info"><i
                    class="fa fa-thumbs-up"></i>
            Zahlung erfassen<sup>1</sup>
        </a>
    {/if}
    {if !$order->amountRefunded || ($order->amount->value > $order->amountRefunded->value && $order->amountCaptured->value > 0)}
        <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=refund&id={$order->id}"
           onclick="return confirm('Zahlung wirklich zurck erstatten?');" class="btn btn-warning"><i
                    class="fa fa-thumbs-down"></i> R&uuml;ckerstatten<sup>2</sup>
        </a>
    {/if}
    {if $order->isCancelable}
        <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=cancel&id={$order->id}"
           onclick="return confirm('Zahlung wirklich stornieren?');" class="btn btn-danger"><i
                    class="fa fa-trash"></i> Stornieren<sup>3</sup>
        </a>
    {/if}
</div>

<table class="table table-condensed table-striped" style="width: 100%">
    <thead>
    <tr>
        <th>Status</th>
        <th>SKU</th>
        <th>Name</th>
        <th>Typ</th>
        <th>Anzahl</th>
        <td>MwSt</td>
        <th>Steuer</th>
        <th>Netto</th>
        <th>Brutto</th>
        <th>&nbsp;</th>
    </tr>
    </thead>
    <tbody>
    {assign var="vat" value=0}
    {assign var="netto" value=0}
    {assign var="brutto" value=0}
    {foreach from=$order->lines item=line}

        {assign var="vat" value=$vat+$line->vatAmount->value}
        {assign var="netto" value=$netto+$line->totalAmount->value-$line->vatAmount->value}
        {assign var="brutto" value=$brutto+$line->totalAmount->value}
        <tr>
            <td>
                {if $line->status == 'created'}
                    <span class="label label-info">erstellt</span>
                {elseif $line->status == 'pending'}
                    <span class="label label-warning">austehend</span>
                {elseif $line->status == 'paid'}
                    <span class="label label-success">bezahlt</span>
                {elseif $line->status == 'authorized'}
                    <span class="label label-success">autorisiert</span>
                {elseif $line->status == 'shipping'}
                    <span class="label label-warning">versendet</span>
                {elseif $line->status == 'completed'}
                    <span class="label label-success">abgeschlossen</span>
                {elseif $line->status == 'expired'}
                    <span class="label label-danger">abgelaufen</span>
                {elseif $line->status == 'canceled'}
                    <span class="label label-danger">storniert</span>
                {else}
                    <span class="label label-danger">Unbekannt: {$line->status}</span>
                {/if}
            </td>
            <td>{$line->sku}</td>
            <td>{$line->name|utf8_decode}</td>
            <td>{$line->type}</td>
            <td>{$line->quantity}</td>
            <td>{$line->vatRate|floatval}%</td>
            <td class="text-right">{$line->vatAmount->value|number_format:2:',':''} {$line->vatAmount->currency}</td>
            <td class="text-right">{($line->totalAmount->value - $line->vatAmount->value)|number_format:2:',':''} {$line->vatAmount->currency}</td>
            <td class="text-right">{$line->totalAmount->value|number_format:2:',':''} {$line->totalAmount->currency}</td>
            <td>
                {*if $line->quantity > $line->quantityShipped}
                    <a onclick="return confirm('Position wirklich zurck erfassen?');" title="Rckersatttung"
                       href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=captureline&id={$line->id}order={$order->id}">
                        <i class="fa fa-thumbs-up"></i>
                    </a>
                {/if}
                {if $line->quantity > $line->quantityRefunded}
                    <a onclick="return confirm('Position wirklich zurck erstatten?');" title="Rckersatttung"
                       href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=refundline&id={$line->id}order={$order->id}">
                        <i class="fa fa-thumbs-down"></i>
                    </a>
                {/if}
                {if $line->isCancelable}
                    <a onclick="return confirm('Position wirklich stornieren?');" title="Stornieren"
                       href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=cancelline&id={$line->id}order={$order->id}">
                        <i class="fa fa-trash"></i>
                    </a>
                {/if*}
                {*$line|var_dump*}
            </td>
        </tr>
    {/foreach}
    </tbody>
    <tfoot>
    <tr>
        <td colspan="5"></td>
        <td class="text-right">{$vat|number_format:2:',':''} {$order->amount->currency}</td>
        <td class="text-right">{$netto|number_format:2:',':''} {$order->amount->currency}</td>
        <td class="text-right">{$brutto|number_format:2:',':''} {$order->amount->currency}</td>
        <td>&nbsp;</td>
    </tr>
    </tfoot>
</table>

<div style="font-size: 10px">
    <sup>1</sup> = Bestellung wird bei Mollie als versandt markiert. WAWI wird <b>nicht</b> informiert.<br/>
    <sup>2</sup> = Bezahlter Betrag wird dem Kunden rckerstattet. WAWI wird <b>nicht</b> informiert.<br/>
    <sup>3</sup> = Bestellung wird bei Mollie storniert. WAWI wird <b>nicht</b> informiert.<br/>
</div>

<h4>Log</h4>

<table class="table table-condensed" style="width: 100%">
    {foreach from=$logs item=log}
        <tr>
            <td>
                {if $log->nLevel == 1}
                    <span class="label label-danger">Fehler</span>
                {elseif $log->nLevel == 2}
                    <span class="label label-warning">Hinweis</span>
                {elseif $log->nLevel == 3}
                    <span class="label label-info">Debug</span>
                {else}
                    <span class="label label-default">unknown {$log->nLevel}</span>
                {/if}
            </td>
            <td>{$log->cModulId}</td>
            <td>
                <div class="logentry" style="overflow: scroll; max-width: 800px; max-height: 400px;">
                    {$log->cLog}
                </div>
            </td>
            <td>{$log->dDatum}</td>
        </tr>
    {/foreach}
</table>

<style>
    .logentry {
        cursor: help;
    }

    .logentry pre {
        display: none;
    }

    .logentry:hover pre, .logentry:focus pre {
        display: block;
    }
</style>
