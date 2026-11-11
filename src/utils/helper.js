import {
    generateUrl,
    generateRemoteUrl
} from '@nextcloud/router'
import Toastify from 'toastify-js'
import "toastify-js/src/toastify.css"
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import contentTable from '../lib/contentTable';
import Http from '../lib/http'
import Polling from "../lib/polling";
import autoComplete from '../lib/autoComplete';

const helper = {
    vue: {},
    addVue(name, object) {
        helper.vue[name] = object;
    },
    getVue(name) {
        return helper.vue[name];
    },
    generateUrl: generateUrl,
    loop(callback, delay = 3000, ...args) {
        Polling.create().setDelay(delay).run(callback, ...args);
    },
    isPolling() {
        return Polling.create().isEnabled();
    },
    enabePolling() {
        Polling.create().enable();
    },
    disablePolling() {
        Polling.create().disable().clear();
    },
    polling(delay = 1500, path) {
        Polling.create().setDelay(delay).run(helper.refresh, path);
    },
    scanFolder(forceScan = false, path = "/apps/ncdownloader/scanfolder") {
        let url = helper.generateUrl(path);
        return new Promise((resolve) => {
            helper.httpClient(url).setData({ "force": forceScan }).setHandler(function (data) {
                resolve(data.status);
            }).send();
        });
    },
    pollingFolder(delay = 1500) {
        Polling.create().setDelay(delay).run(helper.scanFolder);
    },
    pollingYtdl(delay = 1500) {
        Polling.create().setDelay(delay).run(helper.refresh, "/apps/ncdownloader/ytdl/get");
    },
    refresh(path) {
        path = path || "/apps/ncdownloader/status/active";
        let url = helper.generateUrl(path);
        helper.httpClient(url).setHandler(function (data) {
            if (data && data.row) {
                contentTable.getInstance(data.title, data.row).create();
            } else {
                contentTable.getInstance().noData();
            }
            if (data.counter)
                helper.updateCounter(data.counter);
        }).send();
    },
    trim(string, char) {
        return string.split(char).filter(Boolean).join(char)
    },
    isHtml(string) {
        const htmlRegex = new RegExp('^<([a-z]+)[^>]+>(.*?)</\\1>', 'i');
        return htmlRegex.test(string);
    },
    ucfirst(string) {
        return string.charAt(0).toUpperCase() + string.slice(1)
    },
    isURL(url) {
        try {
            new URL(url.trim());
            return true;
        } catch (e) {
            console.log(e.message);
            return false;
        }
    },
    isMagnetURI(url) {
        const magnetURI = /^magnet:\?xt=urn:[a-z0-9]+:[a-z0-9]{32,40}(&dn=.+&tr=.+)?$/i;

        return magnetURI.test(url.trim());
    },
    _message: function (message, options = {}) {
        message = message || "Empty"
        const defaults = {
            text: message,
            newWindow: true,
            close: true,
            gravity: "top", // `top` or `bottom`
            position: "center", // `left`, `center` or `right`
            // backgroundColor: "#295b86",
            stopOnFocus: true, // Prevents dismissing of toast on hover
            onClick: function () { }, // Callback after click
        }
        Object.assign(defaults, options);
        Toastify(defaults).showToast();
    },
    error: function (message, duration = 20000) {
        let options = {
            style: {
                color: '#721c24',
                'background-color': '#f8d7da',
                'border-color': '#f5c6cb',
            },
            duration: duration,
            backgroundColor: '#f8d7da',
        }
        helper._message(message, options);
    },
    info: function (message, duration = 2000) {
        const options = {
            style: {
                color: '#004085',
                'background-color': '#cce5ff',
                'border-color': '#b8daff',
            },
            duration: duration,
            text: message,
            backgroundColor: '#cce5ff',
        }
        helper._message(message, options);
    },
    warn: function (message, duration = 2000) {
        const options = {
            style: {
                color: '#856404',
                'background-color': '#fff3cd',
                'border-color': '#ffeeba',
            },
            duration: duration,
            backgroundColor: '#fff3cd',
        }
        helper._message(message, options);
    },
    message: function (message, duration = 2000) {
        helper.info(message, duration);
    },
    getPathLast: function (path) {
        return path.substring(path.lastIndexOf('/') + 1)
    },
    updateCounter(data) {
        for (let key in data) {
            const counter = document.getElementById(key + "-downloads-counter")
            counter.innerHTML = '<div class="number">' + data[key] + '</div>';
        }
    },
    getCounters() {
        let url = helper.generateUrl("apps/ncdownloader/counters");
        helper.httpClient(url).setMethod("GET").setHandler(function (data) {
            if (data["counter"])
                helper.updateCounter(data["counter"]);
        }).send();
    },
    html2DOM: function (htmlString) {
        const parser = new window.DOMParser();
        let doc = parser.parseFromString(htmlString, "text/html")
        return doc.querySelector("div");
    },
    getData(selector) {
        const element = typeof selector === "object" ? selector : document.getElementById(selector)
        const data = {}
        data['_path'] = element.getAttribute('path') || '';
        //if the targeted element is not of input or select type, search for such elements below it
        if (!['SELECT', 'INPUT'].includes(element.nodeName.toUpperCase())) {
            const nodeList = element.querySelectorAll('input,select')

            for (let i = 0; i < nodeList.length; i++) {
                const element = nodeList[i]
                if (element.hasAttribute('type') && element.getAttribute('type') === 'button') {
                    continue
                }
                const key = element.getAttribute('id') || element.getAttribute('name')
                data[key] = element.value
                for (let prop in element.dataset) {
                    if (prop == "rel") {
                        continue;
                    }
                    data[prop] = element.dataset[prop];
                }
            }
        } else {
            for (let prop in element.dataset) {
                if (prop == "rel") {
                    continue;
                }
                data[prop] = element.dataset[prop];
            }
            const key = element.getAttribute('id') || element.getAttribute('name')
            data[key] = element.value
        }
        return data;
    },
    showElement(prop) {
        let vm = helper.getVue('mainApp');
        vm.$data.display[prop] = true;
        //hide all other elements;
        for (let key in vm.$data.display) {
            if (key !== prop) {
                vm.$data.display[key] = false;
            }
        }
    },
    hideElement(prop) {
        let vm = helper.getVue('mainApp');
        vm.$data.display[prop] = false;
    },
    showDownload() {
        helper.showElement('download');
        contentTable.getInstance().clear();
        //helper.disablePolling();
    },
    hideDownload() {
        helper.hideElement('download');
    },
    topleft(id) {
        let container = typeof id === 'object' ? id : document.getElementById(id);
        container.style.top = 0;
        container.style.left = 0;
        container.style.width = "100%";
    },
    loadingTpl() {
        let html = `<button class="bs-spinner">
        <span
          class="spinner-border spinner-border-sm"
          role="status"
          aria-hidden="true"
          disabled
        ></span
        ><span class="visually-hidden">Loading...</span>`;
        return html;
    },
    getCssVar(prop) {
        return window.getComputedStyle(document.documentElement).getPropertyValue(prop);
    },
    getScrollTop() {
        return window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
    },
    showErrors(target) {
        let errors = document.querySelectorAll(target);
        errors.forEach(element => {
            let msg;
            if (msg = element.getAttribute('data-error-message'))
                helper.error(msg, 20000);
        })
    },
    str2Boolean: function (str) {
        if (typeof str != "string") {
            return false;
        }

        switch (str.toLowerCase().trim()) {
            case "true":
            case "yes":
            case "1":
                return true;

            case "false":
            case "no":
            case "0":
            case null:
                return false;
            default:
                return Boolean(str);
        }
    },
    t: function (str) {
        return t("ncdownloader", str);
    },
    resetSearch: function (vm) {
        vm.$data.loading = 0;
        contentTable.getInstance([], []).clear();
    },
    redirect(url) {
        window.location.href = url;
    },
    getContentTableType() {
        let container = document.getElementById("ncdownloader-table-wrapper");
        return container.getAttribute("type");
    },
    setContentTableType(name) {
        let container = document.getElementById("ncdownloader-table-wrapper");
        container.setAttribute("type", name);
        container.className = "table " + name;
    },
    async filepicker(cb, currentPath) {
        const head = document.querySelector('head');
        const uid = (head && head.getAttribute('data-user')) || '';
        const token = (head && head.getAttribute('data-requesttoken')) || '';
        if (!uid) {
            const manual = window.prompt(t('ncdownloader', 'Select a directory'), currentPath || '/Downloads');
            if (manual) {
                cb(manual.startsWith('/') ? manual : '/' + manual);
            }
            return;
        }

        const existing = document.getElementById('ncd-dir-picker-overlay');
        if (existing) {
            existing.remove();
        }

        let activeDir = (currentPath && typeof currentPath === 'string' ? currentPath.trim() : '/Downloads') || '/';
        if (!activeDir.startsWith('/')) {
            activeDir = '/' + activeDir;
        }
        if (activeDir.length > 1 && activeDir.endsWith('/')) {
            activeDir = activeDir.slice(0, -1);
        }

        const davRoot = (typeof generateRemoteUrl === 'function' ? generateRemoteUrl('dav') : '/remote.php/dav')
            + '/files/' + encodeURIComponent(uid);

        const fetchDirs = async (dirPath) => {
            const clean = dirPath === '/' ? '' : dirPath.split('/').filter(Boolean).map(encodeURIComponent).join('/');
            const url = davRoot + (clean ? '/' + clean : '') + '/';
            const resp = await fetch(url, {
                method: 'PROPFIND',
                headers: {
                    Depth: '1',
                    requesttoken: token,
                    'Content-Type': 'application/xml; charset=utf-8'
                }
            });
            if (!resp.ok) {
                return [];
            }
            const xmlText = await resp.text();
            const doc = new window.DOMParser().parseFromString(xmlText, 'application/xml');
            const responses = Array.from(doc.getElementsByTagNameNS('DAV:', 'response'));
            const folders = [];
            responses.forEach((node, idx) => {
                if (idx === 0) {
                    return;
                }
                const isCollection = node.getElementsByTagNameNS('DAV:', 'collection').length > 0;
                if (!isCollection) {
                    return;
                }
                const hrefNode = node.getElementsByTagNameNS('DAV:', 'href')[0];
                if (!hrefNode || !hrefNode.textContent) {
                    return;
                }
                const marker = '/files/' + encodeURIComponent(uid);
                const pos = hrefNode.textContent.indexOf(marker);
                if (pos === -1) {
                    return;
                }
                let rel = decodeURIComponent(hrefNode.textContent.slice(pos + marker.length));
                if (rel.length > 1 && rel.endsWith('/')) {
                    rel = rel.slice(0, -1);
                }
                if (rel && rel !== dirPath) {
                    folders.push(rel);
                }
            });
            return folders.sort((a, b) => a.localeCompare(b));
        };

        const overlay = document.createElement('div');
        overlay.id = 'ncd-dir-picker-overlay';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:16px;';

        const modal = document.createElement('div');
        modal.style.cssText = 'background:var(--color-main-background,#fff);color:var(--color-main-text,#111);border:1px solid var(--color-border,#ccc);border-radius:10px;max-width:460px;width:100%;padding:16px;box-shadow:0 12px 32px rgba(0,0,0,0.25);display:flex;flex-direction:column;gap:10px;';

        const titleEl = document.createElement('h3');
        titleEl.textContent = t('ncdownloader', 'Select a directory');
        titleEl.style.cssText = 'margin:0;font-size:16px;font-weight:600;';

        const pathInput = document.createElement('input');
        pathInput.type = 'text';
        pathInput.value = activeDir;
        pathInput.style.cssText = 'width:100%;padding:8px 10px;border:1px solid var(--color-border-dark,#94a3b8);border-radius:6px;background:var(--color-main-background,#fff);color:var(--color-main-text,#111);box-sizing:border-box;';

        const listEl = document.createElement('div');
        listEl.style.cssText = 'max-height:240px;overflow-y:auto;border:1px solid var(--color-border,#e2e8f0);border-radius:6px;padding:4px;';

        const renderDir = async (dir) => {
            activeDir = dir;
            pathInput.value = activeDir;
            listEl.innerHTML = '';
            if (activeDir !== '/') {
                const upBtn = document.createElement('div');
                upBtn.textContent = '..';
                upBtn.style.cssText = 'padding:8px 10px;cursor:pointer;border-radius:4px;font-weight:600;';
                upBtn.addEventListener('click', () => {
                    const parent = activeDir.slice(0, activeDir.lastIndexOf('/')) || '/';
                    renderDir(parent);
                });
                listEl.appendChild(upBtn);
            }
            let dirs = [];
            try {
                dirs = await fetchDirs(activeDir);
            } catch (_e) {
                dirs = [];
            }
            if (dirs.length === 0 && activeDir !== '/') {
                dirs = await fetchDirs('/');
                if (dirs.length > 0) {
                    activeDir = '/';
                    pathInput.value = '/';
                    listEl.innerHTML = '';
                }
            }
            dirs.forEach((folderPath) => {
                const row = document.createElement('div');
                const label = folderPath.slice(folderPath.lastIndexOf('/') + 1);
                row.textContent = label;
                row.style.cssText = 'padding:8px 10px;cursor:pointer;border-radius:4px;';
                row.addEventListener('mouseenter', () => {
                    row.style.background = 'var(--color-background-hover,#f1f5f9)';
                });
                row.addEventListener('mouseleave', () => {
                    row.style.background = 'transparent';
                });
                row.addEventListener('click', () => renderDir(folderPath));
                listEl.appendChild(row);
            });
        };

        const footer = document.createElement('div');
        footer.style.cssText = 'display:flex;justify-content:flex-end;gap:8px;margin-top:4px;';

        const cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.cssText = 'padding:6px 14px;border-radius:6px;border:1px solid var(--color-border,#cbd5e1);background:transparent;color:var(--color-main-text,#111);cursor:pointer;';
        cancelBtn.addEventListener('click', () => overlay.remove());

        const chooseBtn = document.createElement('button');
        chooseBtn.type = 'button';
        chooseBtn.textContent = 'Choose';
        chooseBtn.style.cssText = 'padding:6px 14px;border-radius:6px;border:0;background:var(--color-primary-element,#00679e);color:var(--color-primary-element-text,#fff);font-weight:600;cursor:pointer;';
        chooseBtn.addEventListener('click', () => {
            let chosen = (pathInput.value || '/Downloads').trim();
            if (!chosen.startsWith('/')) {
                chosen = '/' + chosen;
            }
            overlay.remove();
            cb(chosen);
        });

        footer.appendChild(cancelBtn);
        footer.appendChild(chooseBtn);
        modal.appendChild(titleEl);
        modal.appendChild(pathInput);
        modal.appendChild(listEl);
        modal.appendChild(footer);
        overlay.appendChild(modal);
        overlay.addEventListener('click', (ev) => {
            if (ev.target === overlay) {
                overlay.remove();
            }
        });
        document.body.appendChild(overlay);
        await renderDir(activeDir);
    },
    getSettings(key, defaultValue = null, type = 2) {
        let url = helper.generateUrl("/apps/ncdownloader/getsettings");
        return new Promise(resolve => {
            helper.httpClient(url).setData({ name: key, type: type, default: defaultValue }).setHandler(data => {
                resolve(data)
            }).send()
        })
    },
    httpClient(url) {
        return new Http.create(url, true)
    },
    autoComplete(selector, options) {
        try {
            autoComplete.getInstance({
                selector: selector,
                minChars: 1,
                sourceHandler: function () {
                    if (Array.isArray(options)) {
                        return options;
                    }
                    return Object.keys(options);
                },
                renderer: (item, search) => {
                    if (!item || !search) {
                        return;
                    }
                    search = search.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
                    let tippy;
                    if (options.hasOwnProperty(item)) {
                        tippy = options[item];
                    } else {
                        tippy = item;
                    }
                    var re = new RegExp(`(${search.split(' ').join('|')})`, "gi");
                    return `<div data-tippy-content="${tippy}" class="suggestion-item" data-val="${item}">${item.replace(re, "<b>$1</b>")}</div>`;
                }
            }).run();
        } catch (error) {
            console.log(error)
            helper.error(error);
        }
    },
    transformParams(data, prefix = "aria2-settings") {
        let index
        for (let key in data) {
            if (key.charAt(0) == "_") {
                delete data[key]
                continue
            }
            if ((index = key.indexOf(prefix + "-key-")) !== -1) {
                let valueKey = prefix + "-value-" + key.substring(key.lastIndexOf('-') + 1);
                if (data[valueKey] === undefined) continue;
                let newkey = data[key];
                data[newkey] = data[valueKey];
                delete data[key];
                delete data[valueKey];
            }
        }
        return data
    },
    isEmptyObject(obj) {
        return Object.keys(obj).length === 0
    }
}

export default helper
