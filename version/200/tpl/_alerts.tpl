{if $alerts}
    {assign var=alert_arr value=$alerts|get_object_vars}
    {if $alert_arr|count}
        <div style="margin-top: 10px; margin-bottom: 10px;" class="alert-group">
            {foreach from=$alert_arr item=alert key=id}
                <div class="alert alert-{'_'|str_replace:' ':$id}">{$alert}</div>
            {/foreach}
        </div>
    {/if}
{/if}
