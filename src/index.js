import helper from './utils/helper';
import eventHandler from './lib/eventHandler';
import { translate as t } from '@nextcloud/l10n';
import updatePage from './actions/updatePage';
import buttonActions from './actions/buttonActions';
import './css/style.scss';
import './css/table.scss';
import { createApp } from 'vue';
import App from './App';
import { delegate } from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import settingsBar from './settingsBar';

const basePath = '/apps/ncdownloader';

function bootMainApp() {
    const wrapper = document.getElementById('app-ncdownloader-wrapper');
    if (!wrapper) {
        return;
    }

    helper.showErrors('[data-error-message]');
    updatePage.run();
    buttonActions.run();

    const container = 'ncdownloader-form-wrapper';
    const dataContainerID = 'app-settings-data';
    const app = createApp(App);
    const bar = createApp(settingsBar);

    const dataContainer = document.getElementById(dataContainerID);
    let values = { settings: {}, search_sites: [] };
    try {
        if (dataContainer) {
            const settings = dataContainer.getAttribute('data-settings');
            const searchSites = dataContainer.getAttribute('data-search-sites');
            values.settings = settings ? JSON.parse(settings) : {};
            values.search_sites = searchSites ? JSON.parse(searchSites) : [];
        }
    } catch (e) {
        values = { settings: {}, search_sites: [] };
        console.error(e);
    }

    const settingsContent = document.getElementById('app-settings-content');
    if (settingsContent) {
        bar.provide('settings', values.settings);
        bar.mount('#app-settings-content');
    }

    const formContainer = document.getElementById(container);
    if (formContainer) {
        app.provide('settings', values);
        const vm = app.mount('#' + container);
        helper.addVue(vm.$options.name, vm);
    }

    // NC 34/35 compatibility: toggle #app-settings-content when core OC.Apps slide-toggle is absent
    const settingsBtn = document.querySelector('#app-settings-header .settings-button');
    if (settingsBtn && settingsContent && (!window.OC || !window.OC.Apps)) {
        settingsContent.style.display = 'none';
        settingsBtn.addEventListener('click', (ev) => {
            ev.preventDefault();
            const hidden = settingsContent.style.display === 'none';
            settingsContent.style.display = hidden ? 'block' : 'none';
        });
    }

    // Responsive mobile navigation drawer toggle
    const navToggle = document.getElementById('app-navigation-toggle');
    const navDrawer = document.getElementById('app-navigation');
    if (navToggle && navDrawer) {
        navToggle.addEventListener('click', (ev) => {
            ev.stopPropagation();
            navDrawer.classList.toggle('ncd-nav-open');
        });
        document.addEventListener('click', (ev) => {
            if (navDrawer.classList.contains('ncd-nav-open') && !navDrawer.contains(ev.target) && ev.target !== navToggle) {
                navDrawer.classList.remove('ncd-nav-open');
            }
        });
    }

    eventHandler.add('click', '#start-aria2', 'button', function (e) {
        const path = basePath + '/aria2/start';
        const element = e.target;
        if (element.classList.contains('notinstalled')) {
            return;
        }
        const parent = element.parentElement;
        const oldHtml = parent.innerHTML;
        parent.innerHTML = helper.loadingTpl();
        const url = helper.generateUrl(path);
        const callback = function (parentEl, html, data) {
            parentEl.innerHTML = html;

            if (!data || !data.status) {
                if (data && data.error) {
                    helper.error(data.error);
                }
                return;
            }
            const btn = document.querySelector('#start-aria2 button');
            if (!btn) {
                return;
            }
            const aria2 = btn.getAttribute('data-aria2');
            if (!aria2) {
                return;
            }
            if (aria2 === 'on') {
                btn.setAttribute('data-aria2', 'off');
                btn.textContent = t('ncdownloader', 'Start Aria2');
            } else {
                btn.setAttribute('data-aria2', 'on');
                btn.textContent = t('ncdownloader', 'Stop Aria2');
            }
        };
        helper.httpClient(url).setHandler(function (data) {
            callback(parent, oldHtml, data);
        }).send();
    });

    eventHandler.add('click', '#app-navigation', '#search-download', helper.showDownload);
    delegate('#app-ncdownloader-wrapper', {
        target: '[data-tippy-content]'
    });
}

if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', bootMainApp);
} else {
    bootMainApp();
}
