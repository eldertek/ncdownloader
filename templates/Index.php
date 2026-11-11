<?php
/** @var array $_ */
?>
<div id="app-ncdownloader-wrapper">
    <?php print_unescaped($this->inc('Navigation')); ?>
    <?php print_unescaped($this->inc('Content')); ?>
    <div id="app-settings-data" data-search-sites="<?php p($_['search_sites'] ?? '[]'); ?>" data-settings="<?php p($_['settings'] ?? '{}'); ?>"></div>
</div>
