import helper from '../utils/helper';
import eventHandler from '../lib/eventHandler';
import Clipboard from '../lib/clipboard';
import '../css/clipboard.scss';

const buttonHandler = (event) => {
    const element = event.currentTarget || event.target.closest('button') || event.target;
    event.stopPropagation();
    event.preventDefault();
    const url = element.getAttribute('path');
    if (!url) {
        return;
    }
    let row = null;
    let data = {};
    const removeRow = true;

    if (element.getAttribute('id') === 'download-action-button') {
        helper.loop(helper.getCounters);
        helper.setContentTableType('search-results');
    }

    const searchRow = element.closest('.table-row-search');
    if (searchRow) {
        row = searchRow;
        if (element.classList.contains('icon-clipboard')) {
            const clippy = new Clipboard(element, row.dataset.link);
            clippy.Copy();
            return;
        }
        data = { url: row.dataset.link };
    } else {
        row = element.closest('.table-row');
        if (row && row.dataset) {
            data = { ...row.dataset };
        }
        if (data['text-input-value']) {
            data.url = data['text-input-value'];
            delete data['text-input-value'];
        }
    }

    helper.httpClient(url).setErrorHandler(function (error) {
        console.error(error);
    }).setHandler(function (resp) {
        if (!resp) {
            return;
        }
        if (Object.prototype.hasOwnProperty.call(resp, 'error')) {
            helper.error(resp.error);
            return;
        }
        if (Object.prototype.hasOwnProperty.call(resp, 'result')) {
            helper.message('Success ' + resp.result);
        }
        if (Object.prototype.hasOwnProperty.call(resp, 'message')) {
            helper.message(resp.message);
        }
        if (row && removeRow) {
            row.remove();
        }
    }).setData(data).send();
};

export default {
    run: function () {
        eventHandler.add('click', '#ncdownloader-table-wrapper', '.table-cell-action-item .button-container button', (e) => buttonHandler(e));
        eventHandler.add('click', '#ncdownloader-table-wrapper', '.table-row button.icon-clipboard', function (e) {
            const element = e.target;
            const clippy = new Clipboard(element);
            clippy.Copy();
        });
    }
};
