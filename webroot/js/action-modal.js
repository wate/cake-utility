(function () {
    'use strict';

    /** @type {HTMLElement|null} */
    var currentTrigger = null;

    /**
     * Bootstrap 4 モーダルを表示する
     *
     * @param {HTMLElement} modalEl モーダル要素
     * @return {void}
     */
    function showModal(modalEl) {
        if (window.bootstrap && window.bootstrap.Modal) {
            var modal = new bootstrap.Modal(modalEl);
            modal.show();
        } else if (window.$) {
            $(modalEl).modal('show');
        }
    }

    /**
     * Bootstrap 4 モーダルを非表示にする
     *
     * @param {HTMLElement} modalEl モーダル要素
     * @return {void}
     */
    function hideModal(modalEl) {
        if (window.bootstrap && window.bootstrap.Modal) {
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
        } else if (window.$) {
            $(modalEl).modal('hide');
        }
    }

    /**
     * トリガーボタンのクリック: data-* を読んでモーダル内容を設定する
     */
    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-action-modal]');
        if (!trigger) return;
        e.preventDefault();

        currentTrigger = trigger;
        var ds = trigger.dataset;
        var targetId = ds.target || 'action-modal';
        var modal = document.getElementById(targetId);
        if (!modal) return;

        // タイトル（data-titleがあれば上書き、なければサーバー側の__d()翻訳を維持）
        var titleEl = modal.querySelector('.modal-title');
        if (titleEl && ds.title) {
            titleEl.textContent = ds.title;
        }

        // メッセージ（data-messageがあれば上書き、なければサーバー側の__d()翻訳を維持）
        var messageEl = modal.querySelector('.action-modal-message');
        if (messageEl) {
            if (ds.message) {
                messageEl.textContent = ds.message
                    .replace(/\{name\}/g, ds.name || '')
                    .replace(/\{action\}/g, ds.action || 'Execute');
            }
            messageEl.style.display = ds.message || messageEl.textContent ? '' : 'none';
        }

        // アクションボタンのテキスト（data-action-text/data-actionがあれば上書き）
        var actionBtn = modal.querySelector('.btn[id$="-action"]');
        if (actionBtn && (ds.actionText || ds.action)) {
            actionBtn.textContent = ds.actionText || ds.action;
        }

        // bodyUrl がある場合は HTMX で内容を読み込む
        var bodyContent = modal.querySelector('.action-modal-body-content');
        if (bodyContent) {
            if (ds.bodyUrl) {
                bodyContent.setAttribute('hx-get', ds.bodyUrl);
                bodyContent.setAttribute('hx-trigger', 'load');
                bodyContent.innerHTML = '';
                if (window.htmx) htmx.process(bodyContent);
            } else {
                bodyContent.removeAttribute('hx-get');
                bodyContent.removeAttribute('hx-trigger');
                bodyContent.innerHTML = '';
            }
        }

        showModal(modal);
    });

    /**
     * アクションボタンのクリック: 動的にフォームを生成して送信する
     */
    document.addEventListener('click', function (e) {
        var actionBtn = e.target.closest('.btn[id$="-action"]');
        if (!actionBtn || !currentTrigger) return;

        var modal = actionBtn.closest('.modal');
        if (!modal) return;

        // 動的にフォームを生成
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = currentTrigger.dataset.url || '';
        form.style.display = 'none';

        // モーダル内の隠しフィールドをフォームにコピー
        var hiddenInputs = modal.querySelectorAll('.modal-body input[type="hidden"]');
        Array.prototype.forEach.call(hiddenInputs, function (input) {
            form.appendChild(input.cloneNode(true));
        });

        // data-method があれば _method を上書き
        var methodInput = form.querySelector('[name="_method"]');
        if (methodInput && currentTrigger.dataset.method) {
            methodInput.value = currentTrigger.dataset.method;
        }

        document.body.appendChild(form);
        form.submit();

        // 送信後、フォーム要素をクリーンアップ
        setTimeout(function () {
            if (form.parentNode) {
                form.parentNode.removeChild(form);
            }
        }, 0);
    });

})();
