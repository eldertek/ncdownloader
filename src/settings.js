import eventHandler from './lib/eventHandler';
import helper from './utils/helper';
import './css/autoComplete.css';
import './css/settings.scss';
import { delegate } from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { createApp } from 'vue';
import adminSettings from './adminSettings';
import personalSettings from './personalSettings';

function bootSettings() {
    const adminEl = document.getElementById('ncdownloader-admin-settings');
    if (adminEl && !adminEl.dataset.mounted) {
        adminEl.dataset.mounted = '1';
        createApp(adminSettings).mount(adminEl);
        delegate(adminEl, { target: '[data-tippy-content]' });
    }

    const personalEl = document.getElementById('ncdownloader-personal-settings');
    if (personalEl && !personalEl.dataset.mounted) {
        personalEl.dataset.mounted = '1';
        createApp(personalSettings).mount(personalEl);
        delegate(personalEl, { target: '[data-tippy-content]' });

        const filepicker = function (event) {
            const element = event.target;
            const cb = function (path) {
                if (this.value !== path) {
                    this.value = path;
                    this.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }.bind(element);
            helper.filepicker(cb, element.value || undefined);
        };
        eventHandler.add('click', '#ncd_downloader_dir', filepicker);
        eventHandler.add('click', '#ncd_torrents_dir', filepicker);
    }
}

if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', bootSettings);
} else {
    bootSettings();
}
