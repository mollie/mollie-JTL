<div style="max-width: 1500px; margin: 0 auto" id="info">
    <div class="row">
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r11">
                <img class="img-responsive" src="https://static.dash.bar/info/r11.png"/>
            </a>
        </div>
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r12">
                <img class="img-responsive" src="https://static.dash.bar/info/r12.png"/>
            </a>
        </div>

        {if isset($update)}
            <div class="col-xs-12 col-sm-4">
                <script>$(function () {
                        $('.nav-tabs .tab-link-info').addClass('update').html("Update verf&uuml;gbar!");
                    });</script>
                <div class="alert alert-success">
                    <h4>Update auf Version {$update->version} verf&uuml;gbar!</h4>
                </div>
                <div>
                    <div>
                        <div class="col-xs-5">Version:</div>
                        <div class="col-xs-7"><strong>{$update->version}</strong></div>
                    </div>
                    <div>
                        <div class="col-xs-5">Erschienen:</div>
                        <div class="col-xs-7"><strong>{$update->create_date}</strong></div>
                    </div>
                    <div>
                        <div class="col-xs-5">Changelog:</div>
                        <div class="col-xs-7">
                        <textarea readonly
                                  rows="{if ($update->changelog|substr_count:"\n") > 6}6{else}{($update->changelog|substr_count:"\n")+1}{/if}"
                                  style="width: 100%">{$update->changelog|utf8_decode|htmlentities}</textarea>
                        </div>
                    </div>

                    <div>
                        <div class="col-xs-5"></div>
                        <div class="col-xs-7 text-center">
                            <a class="btn btn-info" target="_blank"
                               href="{if $update->short_url != ''}{$update->short_url}{else}{$update->full_url}{/if}">Download</a>
                            <a class="btn btn-success"
                               href="plugin.php?kPlugin={$oPlugin->kPlugin}&action=update-plugin">Install</a>
                        </div>
                    </div>
                </div>
            </div>
        {else}
            <div class="col-xs-12 col-sm-4">
                <a target="_blank" href="https://ws-url.de/r13">
                    <img class="img-responsive" src="https://static.dash.bar/info/r13.png"/>
                </a>
            </div>
        {/if}

    </div>
    <div class="row">
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r21">
                <img class="img-responsive" src="https://static.dash.bar/info/r21.png"/>
            </a>
        </div>
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r22">
                <img class="img-responsive" src="https://static.dash.bar/info/r22.png"/>
            </a>
        </div>
        <div class="col-xs-12 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r23">
                <img class="img-responsive" src="https://static.dash.bar/info/r23.png"/>
            </a></div>
    </div>
    <div class="row">
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r31">
                <img class="img-responsive" src="https://static.dash.bar/info/r31.png"/>
            </a>
        </div>
        <div class="col-xs-6 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r32">
                <img class="img-responsive" src="https://static.dash.bar/info/r32.png"/>
            </a>
        </div>
        <div class="col-xs-12 col-sm-4">
            <a target="_blank" href="https://ws-url.de/r33">
                <img class="img-responsive" src="https://static.dash.bar/info/r33.png"/>
            </a>
        </div>
    </div>
</div>
{if file_exists("{$smarty['current_dir']}/_addon.tpl")}
    {include file="{$smarty['current_dir']}/_addon.tpl"}
{/if}
{if isset($oPlugin)}
    <script>
        // <!--

        $(function () {
            $(document).on('click', '.nav-tabs .tab a', function () {
                const target = $(this).attr('href');
                const res = target.match(/plugin-tab-(\d+)/);
                if (res !== null) {
                    const kAdminMenu = parseInt(res[res.index]);
                    history.replaceState(null, 'emarketing', 'plugin.php?kPlugin={$oPlugin->kPlugin}&kPluginAdminMenu=' + kAdminMenu);
                }
            });
        });

        // -->
    </script>
{/if}
