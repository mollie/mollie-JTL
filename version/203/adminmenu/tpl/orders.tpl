{ws_mollie\Helper::showAlerts('orders')}

{if $hasAPIKey == false}
    <a href="https://ws-url.de/mollie-pay" target="_blank" style="display: block; text-align: center">
        <img src="{$admRoot}tpl/mollie-account-erstellen.png" alt="Jetzt kostenlos Mollie Account eröffnen!"
             style="max-width: 100%"/>
    </a>
{else}
    <table class="datatable" style="width: 100%" data-order='[[ 6, "desc" ]]'>
        <thead>
        <tr>
            <th>BestellNr.</th>
            <th>ID</th>
            <th>Mollie Status</th>
            <th>JTL Status</th>
            <th>Betrag</th>
            <th>Methode</th>
            <th>Erstellt</th>
            <th>&nbsp;</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$checkouts item=checkout}
            <tr>
                <td data-order="{$checkout->getModel()->cOrderNumber}">
                    {if $checkout->getModel()->bSynced == false && $checkout->getBestellung()->cAbgeholt === 'Y'}
                        <abbr title="Noch nicht zur WAWI übertragbar" style="cursor: help">*</abbr>
                    {/if}
                    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=order&id={$checkout->getModel()->kID}">{$checkout->getModel()->cOrderNumber}</a>
                    {if $checkout->getModel()->cMode == 'test'}
                        <span class="label label-danger">TEST</span>
                    {/if}
                    {if $checkout->getModel()->bLockTimeout}
                        <span class="label label-danger">LOCK TIMEOUT</span>
                    {/if}
                    {if $checkout->getModel()->dReminder && $checkout->getModel()->dReminder !== '0000-00-00 00:00:00'}
                        <span class="fa fa-envelope"
                              title="Zahlungserinnerung zuletzt verschickt: {"d. M Y H:i"|date:{$checkout->getModel()->dReminder|strtotime}}"></span>
                    {/if}
                </td>
                <td>
                    <a href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=order&id={$checkout->getModel()->kID}">{$checkout->getModel()->kID}</a>
                </td>
                <td class="text-center" data-order="{$checkout->getModel()->cStatus}">
                    {if $checkout->getModel()->cStatus == 'created' || $checkout->getModel()->cStatus == 'open'}
                        <span class="label label-info">erstellt</span>
                    {elseif $checkout->getModel()->cStatus == 'pending'}
                        <span class="label label-warning">austehend</span>
                    {elseif $checkout->getModel()->cStatus == 'paid'}
                        <span class="label label-success">bezahlt</span>
                    {elseif $checkout->getModel()->cStatus == 'authorized'}
                        <span class="label label-success">autorisiert</span>
                    {elseif $checkout->getModel()->cStatus == 'shipping'}
                        <span class="label label-warning">versendet</span>
                    {elseif $checkout->getModel()->cStatus == 'completed'}
                        <span class="label label-success">abgeschlossen</span>
                    {elseif $checkout->getModel()->cStatus == 'expired'}
                        <span class="label label-danger">abgelaufen</span>
                    {elseif $checkout->getModel()->cStatus == 'canceled'}
                        <span class="label label-danger">abgebrochen</span>
                    {else}
                        <span class="label label-danger">Unbekannt: {$checkout->getModel()->cStatus}</span>
                    {/if}
                    {if $checkout->getModel()->fAmountRefunded && $checkout->getModel()->fAmountRefunded == $checkout->getModel()->fAmount}
                        <strong style="color: red">(total refund)</strong>
                    {elseif $checkout->getModel()->fAmountRefunded && $checkout->getModel()->fAmountRefunded > 0}
                        <strong style="color: red">(partly refund)</strong>
                    {/if}

                </td>
                <td>
                    {if $checkout->getBestellung()->cStatus|intval == 1}
                        <span class="label label-info">OFFEN</span>
                    {elseif $checkout->getBestellung()->cStatus|intval == 2}
                        <span class="label label-info">IN BEARBEITUNG</span>
                    {elseif $checkout->getBestellung()->cStatus|intval == 3}
                        <span class="label label-success">BEZAHLT</span>
                    {elseif $checkout->getBestellung()->cStatus|intval == 4}
                        <span class="label label-success">VERSANDT</span>
                    {elseif $checkout->getBestellung()->cStatus|intval == 5}
                        <span class="label label-warning">TEILVERSANDT</span>
                    {elseif $checkout->getBestellung()->cStatus|intval == -1}
                        <span class="label label-danger">STORNO</span>
                    {else}
                        <span class="label label-danger">n/a</span>
                    {/if}
                </td>

                <td class="text-right"
                    data-order="{$checkout->getModel()->fAmount}">{$checkout->getModel()->fAmount|number_format:2:',':''} {$checkout->getModel()->cCurrency}</td>

                <td>{$checkout->getModel()->cMethod}</td>

                <td title="{$checkout->getModel()->dCreatedAt}" class="text-right"
                    data-order="{$checkout->getModel()->dCreatedAt|strtotime}">{"d. M Y H:i"|date:{$checkout->getModel()->dCreatedAt|strtotime}}</td>

                <td>
                    <form action="?kPlugin={$oPlugin->kPlugin}" method="post">
                        <input type="hidden" name="kBestellung" value="{$checkout->getModel()->kBestellung}"/>
                        <div class="btn-group" role="group">
                            {if $checkout->getModel()->bSynced == false && $checkout->getBestellung()->cAbgeholt === 'Y'}
                                <button type="submit" name="action" value="fetchable"
                                        class="btn btn-sm btn-info" title="Für die WAWI abrufbar machen"><span
                                            class="fa fa-unlock"></span></button>
                            {/if}
                            {if $checkout->remindable()}
                                <button type="submit" name="action" value="reminder"
                                        class="btn btn-sm btn-info" title="Zahlungserinnerung verschicken"><span
                                            class="fa fa-envelope"></span></button>
                            {/if}
                        </div>
                    </form>
                </td>
            </tr>
        {/foreach}
        </tbody>
    </table>
    {if $payments|count > 900}
        <div class="text-center"><small>Hier werden nur die letzten 1000 Ergebnisse angezeigt.</small></div>
    {/if}
    <script src="//cdn.webstollen.com/plugin/dataTables/js/jquery.dataTables.min.js"></script>
    <script>
        const cssId = 'datatables';  // you could encode the css path itself to generate id..
        if (!document.getElementById(cssId)) {
            const head = document.getElementsByTagName('head')[0];
            const link = document.createElement('link');
            link.id = cssId;
            link.rel = 'stylesheet';
            link.type = 'text/css';
            link.href = '//cdn.webstollen.com/plugin/dataTables/css/jquery.dataTables.min.css';
            link.media = 'all';
            head.appendChild(link);
        }
        $(document).ready(function () {
            $('.datatable').dataTable({
                stateSave: true,
            });
        });
    </script>
    <div class="row form-inline">
        <div class="col-xs-12">
            <h3>Export:</h3>
        </div>

        <div class="col-xs-12 form-group">
            <label>Von:</label>
            <input class="form-control" type="date" id="exportFrom" placeholder="Export von ..."/>
            <label>Bis:</label>
            <input class="form-control" type="date" id="exportTo" placeholder="... bis"/>
            <button class="btn btn-primary" type="button" id="export">Export</button>
        </div>
    </div>
    <script type="application/javascript">
        $('body').on('click', '#export', function () {
            document.location.href = 'plugin.php?kPlugin={$oPlugin->kPlugin}&action=export&from=' + $('#exportFrom').val() + '&to=' + $('#exportTo').val();
            return false;
        });
    </script>
{/if}

