<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
$downloadsList = [
    ['name' => 'active', 'label' => 'Active Downloads', 'id' => 'active-downloads', 'path' => '/apps/ncdownloader/status/active'],
    ['name' => 'waiting', 'label' => 'Waiting Downloads', 'id' => 'waiting-downloads', 'path' => '/apps/ncdownloader/status/waiting'],
    ['name' => 'fail', 'label' => 'Failed Downloads', 'id' => 'failed-downloads', 'path' => '/apps/ncdownloader/status/fail'],
    ['name' => 'complete', 'label' => 'Complete Downloads', 'id' => 'complete-downloads', 'path' => '/apps/ncdownloader/status/complete'],
    ['name' => 'ytdl', 'label' => 'yt-dlp Downloads', 'id' => 'ytdl-downloads', 'path' => '/apps/ncdownloader/ytdl/get'],
];
?>
<div id="app-navigation">
    <?php if (empty($_['ncd_hide_errors']) && !empty($_['errors']) && is_array($_['errors'])): ?>
        <?php foreach ($_['errors'] as $error): ?>
            <div data-error-message="<?php p($l->t((string) $error)); ?>"></div>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="app-navigation-new" id="search-download" data-inputbox="form-input-wrapper">
        <button type="button" class="icon-add">
            <?php p($l->t('Download & Search')); ?>
        </button>
    </div>
    <?php if (!empty($_['is_admin'])): ?>
    <div class="app-navigation-new" id="start-aria2">
        <?php if (!empty($_['aria2_installed']) && !empty($_['aria2_executable'])): ?>
        <button type="button" class="icon-power"
            data-aria2="<?php p(!empty($_['aria2_running']) ? 'on' : 'off'); ?>">
            <?php p(!empty($_['aria2_running']) ? $l->t('Stop Aria2') : $l->t('Start Aria2')); ?>
        </button>
        <?php elseif (!empty($_['aria2_installed']) && empty($_['aria2_executable'])): ?>
        <button type="button" class="icon-power notinstalled">
            <?php p($l->t('aria2c is installed but not executable')); ?>
        </button>
        <?php else: ?>
        <button type="button" class="icon-power notinstalled">
            <?php p($l->t('aria2c is not installed!')); ?>
        </button>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <ul>
        <?php foreach ($downloadsList as $value): ?>
        <li class="download-queue <?php p($value['id']); ?>">
            <div class="app-navigation-entry-bullet"></div>
            <a role="button" tabindex="0" path="<?php p($value['path']); ?>" id="<?php p($value['id']); ?>">
                <?php p($l->t($value['label'])); ?>
            </a>
            <div class="app-navigation-entry-utils">
                <ul>
                    <li class="app-navigation-entry-utils-counter" id="<?php p($value['name'] . '-downloads-counter'); ?>">
                        <div class="number"><?php p((string) ($_['counter'][$value['name']] ?? 0)); ?></div>
                    </li>
                </ul>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <div id="app-settings">
        <div id="app-settings-header">
            <button
                type="button"
                name="app settings"
                class="settings-button"
                data-apps-slide-toggle="#app-settings-content"
            >
                <?php p($l->t('Settings')); ?>
            </button>
        </div>
        <div id="app-settings-content"></div>
    </div>
</div>
