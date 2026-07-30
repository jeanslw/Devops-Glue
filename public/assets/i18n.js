/**
 * Devops-Glue 前端国际化 (lightweight)
 *
 * 用法:
 *   __.init('zh-CN');          // 初始化（从 localStorage 恢复）
 *   __.t('map.new');           // 翻译
 *   __.lang                    // 当前语言: 'zh-CN' | 'en'
 *   __.switchTo('en');          // 切换语言（会重新渲染）
 *
 * HTML 属性:
 *   <span data-i18n="admin.home">首页</span>
 *   <input data-i18n-placeholder="admin.account_placeholder">
 *
 * 切换语言时会自动更新所有 data-i18n / data-i18n-placeholder 元素。
 * 页面会触发 'i18n-changed' 自定义事件，JS 代码可监听此事件来手动刷新动态内容。
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'dg_lang';
    const LANGS = { 'zh-CN': 'zh_CN', 'en': 'en' };

    let currentLang = 'zh-CN';
    let messages = {};

    window.__ = {
        get lang() { return currentLang; },

        t: function (key, params) {
            var msg = messages[key];
            if (msg === undefined || msg === null) return key;
            if (params) {
                Object.keys(params).forEach(function (k) {
                    msg = msg.replace('{' + k + '}', params[k]);
                });
            }
            return msg;
        },

        init: function (lang) {
            lang = lang || localStorage.getItem(STORAGE_KEY) || 'zh-CN';
            if (!LANGS[lang]) lang = 'zh-CN';
            currentLang = lang;
            localStorage.setItem(STORAGE_KEY, lang);
            this._load(currentLang);
        },

        switchTo: function (lang) {
            if (!LANGS[lang]) lang = 'zh-CN';
            currentLang = lang;
            localStorage.setItem(STORAGE_KEY, lang);
            this._load(currentLang);
        },

        _load: function (lang) {
            var self = this;
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/api/i18n/' + LANGS[lang], true);
            xhr.onload = function () {
                if (xhr.status === 200) {
                    try { messages = JSON.parse(xhr.responseText); } catch (e) {}
                }
                self._applyToDOM();
                document.dispatchEvent(new CustomEvent('i18n-changed', { detail: { lang: currentLang } }));
            };
            xhr.onerror = function () {
                self._applyToDOM();
                document.dispatchEvent(new CustomEvent('i18n-changed', { detail: { lang: currentLang } }));
            };
            xhr.send();
        },

        _applyToDOM: function () {
            var self = this;
            // data-i18n: 设置 textContent
            document.querySelectorAll('[data-i18n]').forEach(function (el) {
                var key = el.getAttribute('data-i18n');
                var translated = self.t(key);
                if (translated !== key) el.textContent = translated;
            });
            // data-i18n-placeholder: 设置 placeholder
            document.querySelectorAll('[data-i18n-placeholder]').forEach(function (el) {
                var key = el.getAttribute('data-i18n-placeholder');
                var translated = self.t(key);
                if (translated !== key) el.setAttribute('placeholder', translated);
            });
            // data-i18n-title: 设置 title
            document.querySelectorAll('[data-i18n-title]').forEach(function (el) {
                var key = el.getAttribute('data-i18n-title');
                var translated = self.t(key);
                if (translated !== key) el.setAttribute('title', translated);
            });
            // 更新 <html lang>
            document.documentElement.lang = currentLang;
            // 更新页面 title
            var titleEl = document.querySelector('title[data-i18n]');
            if (titleEl) {
                var titleKey = titleEl.getAttribute('data-i18n');
                titleEl.textContent = self.t(titleKey);
            }
            // 手动更新语言选择器的 option 文本（浏览器对 <option> 的 textContent 不敏感）
            var labels = { 'zh-CN': { zh: '中文', en: 'English' }, 'en': { zh: 'Chinese', en: 'English' } };
            var l = labels[currentLang] || labels['zh-CN'];
            ['lang-select', 'lang-select-login'].forEach(function (id) {
                var sel = document.getElementById(id);
                if (!sel) return;
                sel.querySelectorAll('option').forEach(function (opt) {
                    if (opt.value === 'zh-CN') opt.text = l.zh;
                    if (opt.value === 'en') opt.text = l.en;
                });
            });
        }
    };
})();
