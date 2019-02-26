{if isset($oMollieException)}
    <div class="alert alert-danger">
        {$oMollieException->getMessage()}
    </div>
{/if}