{*$payments|var_dump*}

{if count($ordersMsgs)}
    {foreach from=$ordersMsgs item=alert}
        <div class="alert alert-{$alert->type}">{$alert->text}</div>
    {/foreach}
    <br/>
{/if}

<table class="datatable" width="100%" data-order='[[ 7, "desc" ]]'>
    <thead>
    <tr>
        <td>BestellNr.</td>
        <th>ID</th>
        <td>Status</td>
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
            <td>{$payment->cOrderNumber}</td>
            <td>
                <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=order&id={$payment->kID}">{$payment->kID}</a>
            </td>
            <td class="text-center" data-order="{$payment->cStatus}">
                {if $payment->cStatus == 'paid'}
                    <span class="label label-success">bezahlt</span>
                {elseif $payment->cStatus == 'created'}
                    <span class="label label-info">erstellt</span>
                {else}
                    {$payment->cStatus}
                {/if}
            </td>
            <td class="text-right">{$payment->fAmount|number_format:2:',':''}</td>
            <td>{$payment->cCurrency}</td>
            <td>{$payment->cLocale}</td>
            <td>{$payment->cMethod}</td>
            <td title="{$payment->dCreatedAt}"
                data-order="{$payment->dCreatedAt|strtotime}">{"d. M Y H:i"|date:($payment->dCreatedAt|strtotime)}</td>
        </tr>
    {/foreach}
    </tbody>
</table>

<script src="//cdn.datatables.net/1.10.19/js/jquery.dataTables.min.js"></script>
<script>
    var cssId = 'datatables';  // you could encode the css path itself to generate id..
    if (!document.getElementById(cssId)) {
        var head = document.getElementsByTagName('head')[0];
        var link = document.createElement('link');
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