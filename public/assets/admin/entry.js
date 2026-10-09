/* Executed in the head so the browser discovers the real application early. */
(function () {
    'use strict';
    var authorization = null, payload = null;
    try {
        authorization = localStorage.getItem('authorization');
        if (authorization) payload = JSON.parse(atob(authorization.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    } catch (ignore) {}
    var entry = window.adminEntry;
    // The server has validated the signed session. This comparison only keeps
    // another tab's identity from executing the wrong page's application.
    window.adminEntryValid = !!(entry && payload && payload.session === entry.session
        && Number(payload.id) === Number(entry.security.user_id));
    window.adminSecurity = window.adminEntryValid ? entry.security : {role: 'guest', menus: [], landing: '/login', version: 0};
    // Discover the selected dictionary alongside the application. Deferred order
    // lets the translation engine register it before the application renders.
    var locale = 'zh-CN';
    try { locale = localStorage.getItem('v2board_admin_locale') || locale; } catch (ignore) {}
    if (['zh-TW', 'en-US', 'ja-JP', 'ko-KR', 'vi-VN', 'ru-RU', 'fa-IR'].indexOf(locale) !== -1) {
        document.write('<script id="admin-entry-dictionary" defer src="/assets/admin/i18n.' + locale
            + '.js?v=' + window.settings.admin_asset_version + '"><\/script>');
    }
    var source = window.adminEntryValid
        ? '/api/v1/' + window.settings.secure_path + '/security/asset?v=' + window.settings.admin_asset_version
        : !authorization ? '/assets/admin/login.js?v=' + window.settings.admin_asset_version : null;
    if (source) {
        // A render-blocking deferred script downloads with the styles and loader,
        // then renders the actual page before its first paint (where supported).
        // No token appears in the URL; the script endpoint validates the cookie.
        document.write('<script id="admin-entry-script" defer blocking="render" src="' + source
            + '" onerror="window.adminAssetLoadFailed=true;window.adminAssetFailure&&window.adminAssetFailure()"><\/script>');
    }
})();
