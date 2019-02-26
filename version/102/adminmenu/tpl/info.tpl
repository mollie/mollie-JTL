<div class="row">
    <div class="col-md-6"></div>
    <div class="col-md-6">
        {if isset($update)}
            <script>$(function(){
                    $('.nav-tabs .tab-link-info').addClass('update').text("Update verfügbar!");
                });</script>
            <div class="alert alert-success">
                <h4>Update auf Version {$update->version} verfügbar!</h4>
            </div>
            <div style="border: 1px solid #ccc; display: block; padding: 10px; background-color: #f5f5f5">
                <div class="row">
                    <div class="col-xs-5">Version:</div>
                    <div class="col-xs-7"><strong>{$update->version}</strong></div>
                </div>
                <div class="row">
                    <div class="col-xs-5">Erschienen:</div>
                    <div class="col-xs-7"><strong>{$update->create_date}</strong></div>
                </div>
                <div class="row">
                    <div class="col-xs-5">Changelog:</div>
                    <div class="col-xs-7">
                        <textarea readonly
                                  rows="{if ($update->changelog|substr_count:"\n") > 6}6{else}{($update->changelog|substr_count:"\n")+1}{/if}"
                                  style="width: 100%">{$update->changelog|htmlentities}</textarea>
                    </div>
                </div>

                <div class="row">
                    <div class="col-xs-5"></div>
                    <div class="col-xs-7 text-center">
                        <a class="btn btn-info" target="_blank"
                           href="{if $update->short_url != ''}{$update->short_url}{else}{$update->full_url}{/if}">Download</a>
                        <a class="btn btn-success"
                           href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=update-plugin">Install</a>
                    </div>
                </div>
            </div>
        {/if}
    </div>
</div>