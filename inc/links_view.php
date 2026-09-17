<?php

/**
 * Markup for the "External links" section, rendered on EVENT_VIEW_BUG_EXTRA.
 *
 * PHP owns the static chrome (widget box + title) and seeds the initial,
 * already provider-decorated link list into #imatic-el-body[data-initial] so the
 * frontend renders without an extra round-trip. React mounts into that element
 * and takes over the interactive body; runtime config (ajax url, csrf, canManage)
 * is injected separately in EVENT_LAYOUT_BODY_END. Access was checked by the
 * caller before including this file.
 *
 * @var ImaticExternalLinksPlugin $this
 */

$t_bug_id  = (int) gpc_get_int('id', 0);
$t_data    = imatic_el_container()->service->list($t_bug_id);
$t_initial = htmlspecialchars(
    json_encode($t_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
    ENT_QUOTES,
    'UTF-8'
);

?>
<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
    <a id="imatic-el-anchor"></a>
    <div class="widget-box widget-color-blue2">
        <div class="widget-header widget-header-small">
            <h4 class="widget-title lighter">
                <i class="ace-icon fa fa-external-link"></i>
                <?php echo string_display_line(lang_get('imatic_el_section_title')) ?>
            </h4>
        </div>
        <div class="widget-body">
            <div class="widget-main no-padding">
                <div id="imatic-el-body"
                     class="imatic-el-body"
                     data-initial="<?php echo $t_initial ?>">
                    <div class="imatic-el-loading">
                        <?php echo string_display_line(lang_get('imatic_el_loading')) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
