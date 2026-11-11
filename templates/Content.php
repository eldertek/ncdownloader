<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="app-content">
    <div class="ncd-mobile-bar">
        <button type="button" id="app-navigation-toggle" class="ncd-mobile-nav-btn" aria-label="<?php p($l->t('Toggle navigation')); ?>">
            &#9776; <?php p($l->t('Queues')); ?>
        </button>
    </div>
    <div id="app-content-wrapper">
        <div class="ncdownloader-body-wrapper" id="ncdownloader-body-wrapper">
            <div id="ncdownloader-form-wrapper" class="ncdownloader-form-wrapper"></div>
            <div id="ncdownloader-table-wrapper" class="table" type="active-downloads"></div>
        </div>
    </div>
</div>
