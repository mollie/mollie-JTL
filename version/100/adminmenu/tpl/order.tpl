<h2>
    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}">&laquo;</a>
    Bestellung: {$oBestellung->cBestellNr} -
    {if (int)$oBestellung->cStatus == 1}
        <span class="label label-info">OFFEN</span>
    {elseif (int)$oBestellung->cStatus == 2}
        <span class="label label-info">IN BEARBEITUNG</span>
    {elseif (int)$oBestellung->cStatus == 3}
        <span class="label label-success">BEZAHLT</span>
    {elseif (int)$oBestellung->cStatus == 4}
        <span class="label label-success">VERSANDT</span>
    {elseif (int)$oBestellung->cStatus == 5}
        <span class="label label-warning">TEILVERSANDT</span>
    {elseif (int)$oBestellung->cStatus == -1}
        <span class="label label-danger">STORNO</span>
    {else}
        <span class="label label-danger">n/a</span>
    {/if}
</h2>

<table class="table" width="100%">
    <tr>
        <th>Mollie ID:</th>
        <td>{$payment->kID}</td>
        <th>Mode:</th>
        <td>{$order->mode}</td>
        <th>Status:</th>
        <td>{$order->status}</td>
    </tr>

    <tr>
        <th>Betrag:</th>
        <td>{$order->amount->value|number_format:2:',':''} {$order->amount->currency}</td>
        <th>Captured:</th>
        <td>{if $order->amountCaptured}{$order->amountCaptured->value|number_format:2:',':''} {$order->amountCaptured->currency}{else}-{/if}</td>
        <th>Refunded:</th>
        <td>{if $order->amountRefunded}{$order->amountRefunded->value|number_format:2:',':''} {$order->amountRefunded->currency}{else}-{/if}</td>
    </tr>

    <tr>
        <th>Method:</th>
        <td>{$order->method}</td>
        <th>Locale:</th>
        <td>{$order->locale}</td>
        <th>Erstellt:</th>
        <td>{"d. M Y H:i:s"|date:($order->createdAt|strtotime)}</td>
    </tr>
</table>

<h4>Positionen:</h4>
<table class="table table-condensed table-striped" width="100%">
    <thead>
    <tr>
        <th>SKU</th>
        <th>Name</th>
        <th>Typ</th>
        <th>Anzahl</th>
        <td>MwSt</td>
        <th>Steuer</th>
        <th>Netto</th>
        <th>Brutto</th>
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
            <td>{$line->sku}</td>
            <td>{$line->name|utf8_decode}</td>
            <td>{$line->type}</td>
            <td>{$line->quantity}</td>
            <td>{(float)$line->vatRate}%</td>
            <td class="text-right">{$line->vatAmount->value|number_format:2:',':''} {$line->vatAmount->currency}</td>
            <td class="text-right">{($line->totalAmount->value - $line->vatAmount->value)|number_format:2:',':''} {$line->vatAmount->currency}</td>
            <td class="text-right">{$line->totalAmount->value|number_format:2:',':''} {$line->totalAmount->currency}</td>
        </tr>
    {/foreach}
    </tbody>
    <tfoot>
    <tr>
        <td colspan="5"></td>
        <td class="text-right">{$vat|number_format:2:',':''} {$order->amount->currency}</td>
        <td class="text-right">{$netto|number_format:2:',':''} {$order->amount->currency}</td>
        <td class="text-right">{$brutto|number_format:2:',':''} {$order->amount->currency}</td>
    </tr>
    </tfoot>
</table>


<h4>Log</h4>

<table class="table table-condensed" width="100%" style="max-width: 100%">
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

{*$logs|var_dump}
{$payment|var_dump}
{$oBestellung|var_dump}
{$order|var_dump*}