<!--suppress HtmlUnknownTarget -->
{if count($ordersMsgs)}
    {foreach from=$ordersMsgs item=alert}
        <div class="alert alert-{$alert->type}">{$alert->text}</div>
    {/foreach}
    <br/>
{/if}

{if $hasAPIKey == false}
    <a href="https://ws-url.de/mollie-pay" target="_blank" style="display: block; text-align: center">
        <img src="{$admRoot}tpl/mollie-account-erstellen.png" alt="Jetzt kostenlos Mollie Account eröffnen!"
             style="max-width: 100%"/>
    </a>
{else}
    <table class="datatable" style="width: 100%" data-order='[[ 8, "desc" ]]'>
        <thead>
        <tr>
            <td>BestellNr.</td>
            <th>ID</th>
            <td>Mollie Status</td>
            <td>JTL Status</td>
            <td>Betrag</td>
            <td>Währung</td>
            <td>Locale</td>
            <td>Methode</td>
            <td>Erstellt</td>
        </tr>
        </thead>
        <tbody>
        {foreach from=$payments item=payment}
            <tr>
                <td>
                    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=order&id={$payment->kID}">{$payment->cOrderNumber}</a>
                    {if $payment->cMode == 'test'}
                        <span class="label label-danger">TEST</span>
                    {/if}
                </td>
                <td>
                    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=order&id={$payment->kID}">{$payment->kID}</a>
                </td>
                <td class="text-center" data-order="{$payment->cStatus}">
                    {if $payment->cStatus == 'created'}
                        <span class="label label-info">erstellt</span>
                    {elseif $payment->cStatus == 'pending'}
                        <span class="label label-warning">austehend</span>
                    {elseif $payment->cStatus == 'paid'}
                        <span class="label label-success">bezahlt</span>
                    {elseif $payment->cStatus == 'authorized'}
                        <span class="label label-success">autorisiert</span>
                    {elseif $payment->cStatus == 'shipping'}
                        <span class="label label-warning">versendet</span>
                    {elseif $payment->cStatus == 'completed'}
                        <span class="label label-success">abgeschlossen</span>
                    {elseif $payment->cStatus == 'expired'}
                        <span class="label label-danger">abgelaufen</span>
                    {elseif $payment->cStatus == 'canceled'}
                        <span class="label label-danger">storniert</span>
                    {else}
                        <span class="label label-danger">Unbekannt: {$payment->cStatus}</span>
                    {/if}
                    {if $payment->fAmountRefunded && $payment->fAmountRefunded == $payment->fAmount}
                        <strong style="color: red">(total refund)</strong>
                    {elseif $payment->fAmountRefunded && $payment->fAmountRefunded > 0}
                        <strong style="color: red">(partly refund)</strong>
                    {/if}

                </td>
                <td>
                    {if $payment->oBestellung->cStatus|intval == 1}
                        <span class="label label-info">OFFEN</span>
                    {elseif $payment->oBestellung->cStatus|intval == 2}
                        <span class="label label-info">IN BEARBEITUNG</span>
                    {elseif $payment->oBestellung->cStatus|intval == 3}
                        <span class="label label-success">BEZAHLT</span>
                    {elseif $payment->oBestellung->cStatus|intval == 4}
                        <span class="label label-success">VERSANDT</span>
                    {elseif $payment->oBestellung->cStatus|intval == 5}
                        <span class="label label-warning">TEILVERSANDT</span>
                    {elseif $payment->oBestellung->cStatus|intval == -1}
                        <span class="label label-danger">STORNO</span>
                    {else}
                        <span class="label label-danger">n/a</span>
                    {/if}
                </td>
                <td class="text-right">{$payment->fAmount|number_format:2:',':''}</td>
                <td>{$payment->cCurrency}</td>
                <td>{$payment->cLocale}</td>
                <td>{$payment->cMethod}</td>
                <td title="{$payment->dCreatedAt}" class="text-right"
                    data-order="{$payment->dCreatedAt|strtotime}">{"d. M Y H:i"|date:($payment->dCreatedAt|strtotime)}</td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    <script src="//cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
    <script>
        const cssId = 'datatables';  // you could encode the css path itself to generate id..
        if (!document.getElementById(cssId)) {
            const head = document.getElementsByTagName('head')[0];
            const link = document.createElement('link');
            link.id = cssId;
            link.rel = 'stylesheet';
            link.type = 'text/css';
            link.href = '//cdn.datatables.net/1.10.19/css/jquery.dataTables.min.css';
            link.media = 'all';
            head.appendChild(link);
        }
        $(document).ready(function () {
            $('.datatable').DataTable();
        });
    </script>
{/if}

