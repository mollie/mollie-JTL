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

{if $methods && $methods|count}
    <table class="table table-striped table-condensed">
        <thead>
        <tr>
            <th>Bild</th>
            <th>ID</th>
            <th>Name</th>
            <th>Preise</th>
        </tr>
        </thead>
        <tbody>
        {foreach from=$methods item=method}
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
            </tr>
        {/foreach}
        </tbody>
    </table>
{else}
    <div class="alert alert-warning">Es konnten keine Methoden abgerufen werden.</div>
{/if}