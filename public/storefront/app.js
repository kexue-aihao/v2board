(function () {
    'use strict';

    var slug = window.STORE_SLUG;
    var key = 'store_auth_' + slug;
    var auth = sessionStorage.getItem(key) || '';
    var currentTradeNo = null;
    var guestConfig = {};
    var authMode = 'login';
    var twoFactorChallenge = '';
    var recaptchaLoader = null;
    var recaptchaWidget = null;
    var recaptchaToken = '';
    var emailCountdown = null;
    var arithmetic = {challenge: null, status: 'idle'};
    var sharedInviteToken = new URLSearchParams(window.location.search).get('shared_invite') || '';

    var message = document.getElementById('message');
    var authSection = document.getElementById('auth-section');
    var account = document.getElementById('account');
    var logout = document.getElementById('logout');
    var loginForm = document.getElementById('login-form');
    var registerForm = document.getElementById('register-form');
    var twoFactorForm = document.getElementById('two-factor-form');
    var authTitle = document.getElementById('auth-title');
    var authCaption = document.getElementById('auth-caption');
    var sendEmailButton = document.getElementById('send-email-code');
    var arithmeticAnswer = document.getElementById('arithmetic-answer');
    var arithmeticStatus = document.getElementById('arithmetic-status');

    function show(value, bad) {
        message.textContent = value || '';
        message.style.color = bad ? '#b42318' : '#027a48';
    }

    function errorMessage(data, fallback) {
        if (data && data.message) return data.message;
        if (data && data.errors) {
            var first = Object.keys(data.errors).map(function (name) {
                return Array.isArray(data.errors[name]) ? data.errors[name][0] : data.errors[name];
            })[0];
            if (first) return first;
        }
        return fallback || '\u8bf7\u6c42\u5931\u8d25\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5';
    }

    function request(url, options) {
        options = options || {};
        var headers = Object.assign({Accept: 'application/json', 'Content-Type': 'application/json'}, options.headers || {});
        return fetch(url, Object.assign({}, options, {headers: headers})).then(function (response) {
            return response.text().then(function (body) {
                var data = {};
                try { data = body ? JSON.parse(body) : {}; } catch (error) {}
                if (!response.ok) {
                    var failure = new Error(errorMessage(data));
                    failure.status = response.status;
                    throw failure;
                }
                return data;
            });
        });
    }

    function api(path, options) {
        options = options || {};
        options.headers = Object.assign({}, options.headers || {});
        if (auth) options.headers.authorization = auth;
        return request('/api/v1/store/' + encodeURIComponent(slug) + path, options);
    }

    function guestApi(path, options) {
        return request('/api/v1' + path, options);
    }

    function formBody(form) {
        var body = {};
        new FormData(form).forEach(function (value, name) {
            if (value !== '') body[name] = value;
        });
        return body;
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[character];
        });
    }

    function money(value) {
        return '\u00a5' + (Number(value) / 100).toFixed(2);
    }

    function status(value) {
        return ({0: '\u5f85\u652f\u4ed8', 1: '\u5f00\u901a\u4e2d', 2: '\u5df2\u53d6\u6d88', 3: '\u5df2\u5b8c\u6210', 4: '\u5df2\u5173\u95ed'})[value] || '\u672a\u77e5\u72b6\u6001';
    }

    function setButtonLoading(button, loading, text) {
        if (!button) return;
        if (loading) {
            button.dataset.originalText = button.textContent;
            button.disabled = true;
            button.textContent = text || '\u5904\u7406\u4e2d...';
            return;
        }
        button.disabled = false;
        if (button.dataset.originalText) button.textContent = button.dataset.originalText;
    }

    function isEnabled(name) {
        return Number(guestConfig[name]) === 1;
    }

    function setAuthenticated(visible) {
        authSection.hidden = visible;
        document.getElementById('orders-section').hidden = !visible;
        account.textContent = visible ? '\u5df2\u767b\u5f55' : '\u672a\u767b\u5f55';
        logout.hidden = !visible;
        if (visible) {
            loadOrders();
            loadSharedSubscription().then(function () {
                return sharedInviteToken ? acceptSharedInvitation() : null;
            }).catch(function (error) { show(error.message, true); });
        }
    }

    function clearAuthentication(notice) {
        auth = '';
        currentTradeNo = null;
        sessionStorage.removeItem(key);
        document.getElementById('checkout-section').hidden = true;
        document.getElementById('shared-section').hidden = true;
        setAuthenticated(false);
        setAuthMode('login');
        if (notice) show(notice, true);
    }

    function acceptAuthentication(data) {
        if (data && data.auth_data) {
            auth = data.auth_data;
            sessionStorage.setItem(key, auth);
            twoFactorChallenge = '';
            twoFactorForm.hidden = true;
            setAuthenticated(true);
            show('\u767b\u5f55\u6210\u529f');
            return true;
        }
        if (data && data.two_factor_required && data.challenge) {
            twoFactorChallenge = data.challenge;
            loginForm.hidden = true;
            registerForm.hidden = true;
            twoFactorForm.hidden = false;
            authTitle.textContent = '\u4e24\u6b65\u9a8c\u8bc1';
            authCaption.textContent = '\u8bf7\u8f93\u5165\u9a8c\u8bc1\u5668\u4ee3\u7801\u6216\u6062\u590d\u7801\u3002';
            show('');
            return false;
        }
        if (data && data.two_factor_setup_required) {
            throw new Error('\u8be5\u8d26\u53f7\u9700\u5148\u5728\u4e3b\u7ad9\u5b8c\u6210\u4e24\u6b65\u9a8c\u8bc1\u8bbe\u7f6e\u3002');
        }
        throw new Error('\u767b\u5f55\u672a\u5b8c\u6210\uff0c\u8bf7\u7a0d\u540e\u91cd\u8bd5');
    }

    function resetRecaptcha() {
        recaptchaToken = '';
        if (recaptchaWidget !== null && window.grecaptcha && window.grecaptcha.reset) {
            window.grecaptcha.reset(recaptchaWidget);
        }
    }

    function loadRecaptchaApi() {
        if (window.grecaptcha) return Promise.resolve(window.grecaptcha);
        if (recaptchaLoader) return recaptchaLoader;
        recaptchaLoader = new Promise(function (resolve, reject) {
            var finish = function () {
                if (window.grecaptcha) resolve(window.grecaptcha);
                else reject(new Error('reCAPTCHA \u52a0\u8f7d\u5931\u8d25'));
            };
            var existing = document.querySelector('script[data-store-recaptcha]');
            if (existing) {
                existing.addEventListener('load', finish, {once: true});
                existing.addEventListener('error', function () { reject(new Error('reCAPTCHA \u52a0\u8f7d\u5931\u8d25')); }, {once: true});
                return;
            }
            var script = document.createElement('script');
            script.src = 'https://www.recaptcha.net/recaptcha/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.dataset.storeRecaptcha = '1';
            script.onload = finish;
            script.onerror = function () { reject(new Error('reCAPTCHA \u52a0\u8f7d\u5931\u8d25')); };
            document.head.appendChild(script);
        });
        return recaptchaLoader;
    }

    function ensureRecaptcha() {
        var field = document.getElementById('recaptcha-field');
        if (!isEnabled('is_recaptcha')) {
            field.hidden = true;
            return Promise.resolve();
        }
        if (!guestConfig.recaptcha_site_key) {
            return Promise.reject(new Error('reCAPTCHA \u7ad9\u70b9\u5bc6\u94a5\u672a\u914d\u7f6e'));
        }
        field.hidden = false;
        return loadRecaptchaApi().then(function (recaptcha) {
            return new Promise(function (resolve) {
                recaptcha.ready(function () {
                    if (recaptchaWidget !== null) {
                        resetRecaptcha();
                        resolve();
                        return;
                    }
                    recaptchaWidget = recaptcha.render(field, {
                        sitekey: guestConfig.recaptcha_site_key,
                        callback: function (token) { recaptchaToken = token || ''; },
                        'expired-callback': function () { recaptchaToken = ''; },
                        'error-callback': function () { recaptchaToken = ''; }
                    });
                    resolve();
                });
            });
        });
    }

    function setArithmeticStatus(text, state) {
        arithmeticStatus.textContent = text || '';
        arithmeticStatus.className = 'security-status' + (state ? ' is-' + state : '');
    }

    function loadArithmeticChallenge() {
        if (!isEnabled('is_arithmetic_verification')) return Promise.resolve();
        setArithmeticStatus('\u6b63\u5728\u83b7\u53d6\u7b97\u672f\u9a8c\u8bc1\u9898...');
        return guestApi('/guest/comm/arithmetic').then(function (response) {
            arithmetic.challenge = response.data && response.data.enabled === false ? null : response.data;
            arithmetic.status = 'idle';
            arithmeticAnswer.value = '';
            if (!arithmetic.challenge || !arithmetic.challenge.challenge_id) {
                throw new Error('\u65e0\u6cd5\u83b7\u53d6\u7b97\u672f\u9a8c\u8bc1\u9898');
            }
            document.getElementById('arithmetic-expression').textContent = arithmetic.challenge.left + ' ' + arithmetic.challenge.operator + ' ' + arithmetic.challenge.right + ' = ?';
            setArithmeticStatus('');
        }).catch(function (error) {
            arithmetic.challenge = null;
            setArithmeticStatus(error.message, 'error');
            throw error;
        });
    }

    function verifyArithmetic() {
        var answer = arithmeticAnswer.value.trim();
        if (!arithmetic.challenge || !/^\d+$/.test(answer)) {
            arithmetic.status = 'incorrect';
            setArithmeticStatus('\u8bf7\u8f93\u5165\u6b63\u786e\u7684\u975e\u8d1f\u6574\u6570\u7b54\u6848\u3002', 'error');
            return Promise.resolve(false);
        }
        var button = document.getElementById('verify-arithmetic');
        setButtonLoading(button, true, '\u9a8c\u8bc1\u4e2d...');
        return guestApi('/guest/comm/arithmetic/verify', {
            method: 'POST',
            body: JSON.stringify({challenge_id: arithmetic.challenge.challenge_id, answer: answer})
        }).then(function (response) {
            var correct = Boolean(response.data && response.data.correct);
            arithmetic.status = correct ? 'correct' : 'incorrect';
            setArithmeticStatus(correct ? '\u7b54\u6848\u6b63\u786e\u3002' : '\u7b54\u6848\u4e0d\u6b63\u786e\uff0c\u8bf7\u91cd\u65b0\u8f93\u5165\u3002', correct ? 'good' : 'error');
            return correct;
        }).catch(function (error) {
            arithmetic.status = 'incorrect';
            setArithmeticStatus(error.message, 'error');
            return false;
        }).then(function (correct) {
            setButtonLoading(button, false);
            return correct;
        });
    }

    function applyRegistrationRequirements() {
        var registerActive = authMode === 'register';
        var inviteInput = registerForm.elements.invite_code;
        var inviteRequired = isEnabled('is_invite_force');
        inviteInput.required = inviteRequired;
        document.getElementById('invite-note').textContent = inviteRequired ? '\u5f53\u524d\u7ad9\u70b9\u6ce8\u518c\u9700\u8981\u9080\u8bf7\u7801\u3002' : '\u6ca1\u6709\u9080\u8bf7\u7801\u53ef\u7559\u7a7a\u3002';

        var emailVerification = isEnabled('is_email_verify');
        var emailCodeField = document.getElementById('email-code-field');
        emailCodeField.hidden = !emailVerification;
        registerForm.elements.email_code.required = emailVerification;

        var arithmeticField = document.getElementById('arithmetic-field');
        arithmeticField.hidden = !registerActive || !isEnabled('is_arithmetic_verification');
        if (!arithmeticField.hidden && !arithmetic.challenge) {
            loadArithmeticChallenge().catch(function (error) { show(error.message, true); });
        }

        if (registerActive) ensureRecaptcha().catch(function (error) { show(error.message, true); });
        else document.getElementById('recaptcha-field').hidden = true;
    }

    function setAuthMode(mode) {
        authMode = mode === 'register' ? 'register' : 'login';
        twoFactorChallenge = '';
        twoFactorForm.hidden = true;
        loginForm.hidden = authMode !== 'login';
        registerForm.hidden = authMode !== 'register';
        authTitle.textContent = authMode === 'login' ? '\u767b\u5f55\u8d26\u6237' : '\u521b\u5efa\u8d26\u6237';
        authCaption.textContent = authMode === 'login'
            ? '\u767b\u5f55\u540e\u5373\u53ef\u8d2d\u4e70\u5957\u9910\u5e76\u67e5\u770b\u8ba2\u5355\u3002'
            : '\u6ce8\u518c\u540e\u53ef\u76f4\u63a5\u5728\u5f53\u524d\u5e97\u94fa\u8d2d\u4e70\u5957\u9910\u3002';
        document.querySelectorAll('[data-auth-mode]').forEach(function (button) {
            var active = button.dataset.authMode === authMode;
            button.classList.toggle('is-active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        if (authMode === 'register') applyRegistrationRequirements();
        show('');
    }

    function loadStoreConfig() {
        return api('/config').then(function (response) {
            var data = response.data || {};
            document.title = data.store_name || '\u8ba2\u9605\u5e97\u94fa';
            document.getElementById('store-name').textContent = data.store_name || '\u8ba2\u9605\u5e97\u94fa';
            document.getElementById('store-description').textContent = data.store_description || '\u9009\u62e9\u9002\u5408\u4f60\u7684\u8ba2\u9605\u5957\u9910\u3002';
        }).catch(function (error) { show(error.message, true); });
    }

    function loadGuestConfig() {
        return guestApi('/guest/comm/config').then(function (response) {
            guestConfig = response.data || {};
            applyRegistrationRequirements();
        }).catch(function (error) { show(error.message, true); });
    }

    function loadPlans() {
        return api('/plans').then(function (response) {
            var periods = ['month_price', 'quarter_price', 'half_year_price', 'year_price', 'two_year_price', 'three_year_price', 'onetime_price'];
            var labels = {month_price: '\u6708\u4ed8', quarter_price: '\u5b63\u4ed8', half_year_price: '\u534a\u5e74\u4ed8', year_price: '\u5e74\u4ed8', two_year_price: '\u4e24\u5e74\u4ed8', three_year_price: '\u4e09\u5e74\u4ed8', onetime_price: '\u4e00\u6b21\u6027'};
            var plans = response.data || [];
            document.getElementById('plans').innerHTML = plans.length ? plans.map(function (plan) {
                var choices = periods.filter(function (period) { return Number(plan[period]) > 0; }).map(function (period) {
                    return '<button type="button" data-plan="' + Number(plan.id) + '" data-period="' + period + '">' + labels[period] + ' \u00b7 ' + money(plan[period]) + '</button>';
                }).join('');
                return '<article class="plan"><h3>' + escapeHtml(plan.name) + '</h3><p>' + escapeHtml(plan.content || '\u57fa\u7840\u8ba2\u9605\u5957\u9910') + '</p><div class="plan-actions">' + choices + '</div></article>';
            }).join('') : '<div class="empty">\u5f53\u524d\u6682\u65e0\u53ef\u552e\u5957\u9910</div>';
        }).catch(function (error) { show(error.message, true); });
    }

    function formatBytes(value) {
        var size = Number(value || 0);
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;
        while (size >= 1024 && index < units.length - 1) { size /= 1024; index += 1; }
        return (index === 0 ? Math.round(size) : size.toFixed(2)) + ' ' + units[index];
    }

    function decorateSharedPlans() {
        return api('/plans').then(function (response) {
            var plans = response.data || [];
            var cards = document.querySelectorAll('#plans .plan');
            plans.forEach(function (plan, index) {
                if (Number(plan.shared_member_limit || 1) <= 1 || !cards[index]) return;
                var badge = document.createElement('small');
                badge.className = 'shared-plan-note';
                badge.textContent = '\u5171\u4eab\u5957\u9910 \u00b7 \u6700\u591a ' + Number(plan.shared_member_limit) + ' \u4eba\uff08\u542b\u8d2d\u4e70\u8005\uff09\u5171\u4eab\u6d41\u91cf\u4e0e\u8bbe\u5907\u989d\u5ea6';
                cards[index].querySelector('p').insertAdjacentElement('afterend', badge);
            });
        }).catch(function () {});
    }

    function installSharedPanel() {
        if (document.getElementById('shared-section')) return;
        var orders = document.getElementById('orders-section');
        if (!orders) return;
        var style = document.createElement('style');
        style.textContent = '.shared-progress{display:grid;gap:9px;padding:14px;border:1px solid #dce5f1;border-radius:8px;background:#f8fbff}.shared-progress>strong{font-size:15px}.shared-progress>span{color:#475467;font-size:13px}.shared-progress p{margin:0;color:#475467;font-size:13px}.shared-track{height:7px;overflow:hidden;border-radius:999px;background:#e6edf5}.shared-track i{display:block;height:100%;border-radius:inherit;background:#246bce}.shared-plan-note{display:block;margin:-8px 0 12px;color:#246bce;font-size:12px;line-height:1.5}';
        document.head.appendChild(style);
        var section = document.createElement('section');
        section.id = 'shared-section';
        section.className = 'section';
        section.hidden = true;
        section.innerHTML = '<div class="section-head"><div><h2>\u5171\u4eab\u5957\u9910</h2><p class="subtle">\u5168\u7ec4\u5171\u4eab\u4e00\u6761\u8ba2\u9605\u3001\u6d41\u91cf\u989d\u5ea6\u548c\u8bbe\u5907\u6570\u3002</p></div></div>'
            + '<div id="shared-summary"></div>'
            + '<div id="shared-owner-controls" hidden><form id="shared-invite-form" class="auth-form"><label>\u9080\u8bf7\u6210\u5458\u90ae\u7bb1<input name="email" type="email" placeholder="name@example.com" required></label><div class="form-actions"><button type="submit">\u751f\u6210\u9080\u8bf7\u94fe\u63a5</button><button id="rotate-shared-credential" class="secondary" type="button">\u8f6e\u6362\u5171\u4eab\u8ba2\u9605\u51ed\u636e</button></div></form><div id="shared-members"></div><div id="shared-invitations"></div></div>';
        orders.insertAdjacentElement('afterend', section);

        document.getElementById('shared-invite-form').addEventListener('submit', function (event) {
            event.preventDefault();
            var button = event.target.querySelector('button[type="submit"]');
            setButtonLoading(button, true, '\u751f\u6210\u4e2d...');
            api('/shared/invitations', {method: 'POST', body: JSON.stringify(formBody(event.target))}).then(function (response) {
                var data = response.data || {};
                show('\u9080\u8bf7\u94fe\u63a5\u5df2\u751f\u6210\uff1a' + (data.invite_url || ''));
                if (data.invite_url && navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(data.invite_url).catch(function () {});
                event.target.reset();
                loadSharedSubscription();
            }).catch(function (error) { show(error.message, true); }).then(function () { setButtonLoading(button, false); });
        });
        document.getElementById('rotate-shared-credential').addEventListener('click', function (event) {
            var button = event.currentTarget;
            setButtonLoading(button, true, '\u8f6e\u6362\u4e2d...');
            api('/shared/credential/rotate', {method: 'POST', body: '{}'}).then(function () {
                show('\u5171\u4eab\u8ba2\u9605\u51ed\u636e\u5df2\u8f6e\u6362\uff0c\u8bf7\u6210\u5458\u91cd\u65b0\u83b7\u53d6\u8ba2\u9605\u5730\u5740\u3002');
                loadSharedSubscription();
            }).catch(function (error) { show(error.message, true); }).then(function () { setButtonLoading(button, false); });
        });
        document.getElementById('shared-members').addEventListener('click', function (event) {
            var button = event.target.closest('[data-remove-shared-member]');
            if (!button) return;
            if (!window.confirm('\u79fb\u9664\u540e\u4f1a\u7acb\u5373\u8f6e\u6362\u5168\u7ec4\u8ba2\u9605\u51ed\u636e\u3002\u662f\u5426\u7ee7\u7eed\uff1f')) return;
            setButtonLoading(button, true, '\u79fb\u9664\u4e2d...');
            api('/shared/members/' + encodeURIComponent(button.dataset.removeSharedMember) + '/remove', {method: 'POST', body: '{}'}).then(function () {
                show('\u6210\u5458\u5df2\u79fb\u9664\uff0c\u5171\u4eab\u51ed\u636e\u5df2\u8f6e\u6362\u3002');
                loadSharedSubscription();
            }).catch(function (error) { show(error.message, true); }).then(function () { setButtonLoading(button, false); });
        });
    }

    function renderSharedGroup(group) {
        var section = document.getElementById('shared-section');
        var summary = document.getElementById('shared-summary');
        var ownerControls = document.getElementById('shared-owner-controls');
        if (!group) {
            section.hidden = true;
            ownerControls.hidden = true;
            return;
        }
        section.hidden = false;
        var percent = Number(group.usage_percent || 0);
        summary.innerHTML = '<div class="shared-progress"><strong>' + escapeHtml(group.plan_name || '\u5171\u4eab\u5957\u9910') + '</strong><span>' + Number(group.member_count) + '/' + Number(group.member_limit) + ' \u4eba</span><div class="shared-track"><i style="width:' + percent + '%"></i></div><p>' + formatBytes(group.used) + ' / ' + formatBytes(group.total) + ' \u00b7 \u5269\u4f59 ' + formatBytes(group.remaining) + ' \u00b7 ' + percent + '%</p><p class="field-note">\u5171\u4eab\u5957\u9910\u4e0d\u63d0\u4f9b\u4e2a\u4eba\u6d41\u91cf\u4f7f\u7528\u8bb0\u5f55\u3002</p></div>';
        ownerControls.hidden = !group.is_owner;
    }

    function loadSharedSubscription() {
        return api('/shared/subscription').then(function (response) {
            var group = response.data || null;
            renderSharedGroup(group);
            if (!group || !group.is_owner) return null;
            return Promise.all([api('/shared/members'), api('/shared/invitations')]).then(function (results) {
                var members = results[0].data || [];
                var invitations = results[1].data || [];
                document.getElementById('shared-members').innerHTML = members.map(function (member) {
                    var action = member.role === 'owner' || member.status !== 'active' ? '' : '<button class="text-button" type="button" data-remove-shared-member="' + Number(member.id) + '">\u79fb\u9664</button>';
                    return '<div class="order-row"><strong>' + escapeHtml(member.email || '-') + '</strong><span>' + escapeHtml(member.role === 'owner' ? '\u7fa4\u4e3b' : '\u6210\u5458') + ' \u00b7 ' + escapeHtml(member.status || '-') + ' ' + action + '</span></div>';
                }).join('');
                document.getElementById('shared-invitations').innerHTML = invitations.length ? invitations.map(function (invite) {
                    return '<div class="order-row"><strong>' + escapeHtml(invite.email || '-') + '</strong><span>' + escapeHtml(invite.status || '-') + '</span></div>';
                }).join('') : '';
            });
        });
    }

    function acceptSharedInvitation() {
        var token = sharedInviteToken;
        if (!token) return Promise.resolve();
        sharedInviteToken = '';
        return api('/shared/invitations/accept', {method: 'POST', body: JSON.stringify({token: token})}).then(function () {
            window.history.replaceState({}, document.title, window.location.pathname);
            show('\u5df2\u52a0\u5165\u5171\u4eab\u5957\u9910\u3002');
            return loadSharedSubscription();
        }).catch(function (error) {
            show(error.message, true);
        });
    }

    function loadOrders() {
        return api('/order/fetch').then(function (response) {
            var result = response.data || {};
            var orders = result.data || [];
            document.getElementById('orders').innerHTML = orders.length ? orders.map(function (order) {
                return '<div class="order-row"><strong>' + escapeHtml(order.plan ? order.plan.name : order.trade_no) + '</strong><span>' + status(order.status) + ' \u00b7 ' + money(order.total_amount) + '</span></div>';
            }).join('') : '<div class="empty">\u6682\u65e0\u8ba2\u5355</div>';
        }).catch(function (error) {
            if (error.status === 401 || error.status === 403) clearAuthentication('\u767b\u5f55\u5df2\u5931\u6548\uff0c\u8bf7\u91cd\u65b0\u767b\u5f55\u3002');
            else show(error.message, true);
        });
    }

    function loadPayments() {
        return api('/payments').then(function (response) {
            var payments = response.data || [];
            document.getElementById('payment-method').innerHTML = payments.length ? payments.map(function (payment) {
                return '<option value="' + Number(payment.id) + '">' + escapeHtml(payment.name) + '</option>';
            }).join('') : '<option value="">\u6682\u65e0\u53ef\u7528\u652f\u4ed8\u65b9\u5f0f</option>';
        }).catch(function (error) { show(error.message, true); });
    }

    function startEmailCountdown() {
        var left = 60;
        window.clearInterval(emailCountdown);
        sendEmailButton.disabled = true;
        sendEmailButton.textContent = left + '\u79d2\u540e\u91cd\u53d1';
        emailCountdown = window.setInterval(function () {
            left -= 1;
            if (left <= 0) {
                window.clearInterval(emailCountdown);
                emailCountdown = null;
                sendEmailButton.disabled = false;
                sendEmailButton.textContent = '\u53d1\u9001\u9a8c\u8bc1\u7801';
                return;
            }
            sendEmailButton.textContent = left + '\u79d2\u540e\u91cd\u53d1';
        }, 1000);
    }

    document.querySelectorAll('[data-auth-mode]').forEach(function (button) {
        button.addEventListener('click', function () { setAuthMode(button.dataset.authMode); });
    });

    loginForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var button = event.target.querySelector('button[type="submit"]');
        var body = formBody(event.target);
        setButtonLoading(button, true, '\u767b\u5f55\u4e2d...');
        api('/passport/login', {method: 'POST', body: JSON.stringify(body)}).then(function (response) {
            acceptAuthentication(response.data || {});
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    registerForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = formBody(event.target);
        if (body.password !== body.password_confirmation) {
            show('\u4e24\u6b21\u8f93\u5165\u7684\u5bc6\u7801\u4e0d\u4e00\u81f4\u3002', true);
            return;
        }
        if (isEnabled('is_email_verify') && !/^\d{6}$/.test(body.email_code || '')) {
            show('\u8bf7\u8f93\u5165 6 \u4f4d\u90ae\u7bb1\u9a8c\u8bc1\u7801\u3002', true);
            return;
        }
        if (isEnabled('is_invite_force') && !body.invite_code) {
            show('\u5f53\u524d\u6ce8\u518c\u9700\u8981\u9080\u8bf7\u7801\u3002', true);
            return;
        }
        if (isEnabled('is_recaptcha') && !recaptchaToken) {
            show('\u8bf7\u5148\u5b8c\u6210 reCAPTCHA \u9a8c\u8bc1\u3002', true);
            return;
        }

        var securityCheck = isEnabled('is_arithmetic_verification') && arithmetic.status !== 'correct'
            ? verifyArithmetic()
            : Promise.resolve(true);
        securityCheck.then(function (verified) {
            if (!verified) {
                show('\u8bf7\u5148\u5b8c\u6210\u7b97\u672f\u9a8c\u8bc1\u3002', true);
                return;
            }
            if (isEnabled('is_arithmetic_verification')) {
                body.arithmetic_challenge_id = arithmetic.challenge && arithmetic.challenge.challenge_id;
                body.arithmetic_answer = arithmeticAnswer.value.trim();
            }
            if (recaptchaToken) body.recaptcha_data = recaptchaToken;
            var button = event.target.querySelector('button[type="submit"]');
            setButtonLoading(button, true, '\u6ce8\u518c\u4e2d...');
            api('/passport/register', {method: 'POST', body: JSON.stringify(body)}).then(function (response) {
                acceptAuthentication(response.data || {});
                event.target.reset();
                resetRecaptcha();
            }).catch(function (error) {
                show(error.message, true);
                resetRecaptcha();
            }).then(function () { setButtonLoading(button, false); });
        });
    });

    sendEmailButton.addEventListener('click', function () {
        var email = registerForm.elements.email.value.trim();
        if (!email) {
            show('\u8bf7\u5148\u8f93\u5165\u90ae\u7bb1\u5730\u5740\u3002', true);
            return;
        }
        if (isEnabled('is_recaptcha') && !recaptchaToken) {
            show('\u8bf7\u5148\u5b8c\u6210 reCAPTCHA \u9a8c\u8bc1\u3002', true);
            return;
        }
        setButtonLoading(sendEmailButton, true, '\u53d1\u9001\u4e2d...');
        var body = {email: email, isforget: 0};
        if (recaptchaToken) body.recaptcha_data = recaptchaToken;
        guestApi('/passport/comm/sendEmailVerify', {method: 'POST', body: JSON.stringify(body)}).then(function () {
            show('\u9a8c\u8bc1\u7801\u5df2\u53d1\u9001\u3002');
            resetRecaptcha();
            startEmailCountdown();
        }).catch(function (error) {
            show(error.message, true);
            setButtonLoading(sendEmailButton, false);
        });
    });

    document.getElementById('verify-arithmetic').addEventListener('click', function () { verifyArithmetic(); });
    document.getElementById('refresh-arithmetic').addEventListener('click', function () {
        loadArithmeticChallenge().catch(function (error) { show(error.message, true); });
    });
    arithmeticAnswer.addEventListener('input', function () {
        if (arithmetic.status !== 'idle') {
            arithmetic.status = 'idle';
            setArithmeticStatus('');
        }
    });

    twoFactorForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var body = formBody(event.target);
        if (!body.code && !body.recovery_code) {
            show('\u8bf7\u8f93\u5165\u9a8c\u8bc1\u5668\u4ee3\u7801\u6216\u6062\u590d\u7801\u3002', true);
            return;
        }
        var button = event.target.querySelector('button[type="submit"]');
        setButtonLoading(button, true, '\u9a8c\u8bc1\u4e2d...');
        body.challenge = twoFactorChallenge;
        api('/passport/verify2fa', {method: 'POST', body: JSON.stringify(body)}).then(function (response) {
            acceptAuthentication(response.data || {});
        }).catch(function (error) {
            show(error.message, true);
        }).then(function () { setButtonLoading(button, false); });
    });

    document.getElementById('back-to-login').addEventListener('click', function () { setAuthMode('login'); });

    document.getElementById('plans').addEventListener('click', function (event) {
        var button = event.target.closest('[data-plan]');
        if (!button) return;
        if (!auth) {
            show('\u8bf7\u5148\u767b\u5f55\u6216\u6ce8\u518c\u3002', true);
            setAuthMode('login');
            return;
        }
        api('/order/save', {method: 'POST', body: JSON.stringify({plan_id: button.dataset.plan, period: button.dataset.period})}).then(function (response) {
            currentTradeNo = response.data;
            document.getElementById('trade-no').textContent = currentTradeNo;
            document.getElementById('checkout-section').hidden = false;
            loadPayments();
            loadOrders();
            show('\u8ba2\u5355\u5df2\u521b\u5efa\uff0c\u8bf7\u9009\u62e9\u652f\u4ed8\u65b9\u5f0f\u3002');
        }).catch(function (error) { show(error.message, true); });
    });

    document.getElementById('checkout').addEventListener('click', function () {
        if (!currentTradeNo) return;
        var method = document.getElementById('payment-method').value;
        if (!method) {
            show('\u5f53\u524d\u5e97\u94fa\u6682\u65e0\u53ef\u7528\u652f\u4ed8\u65b9\u5f0f\u3002', true);
            return;
        }
        api('/order/checkout', {method: 'POST', body: JSON.stringify({trade_no: currentTradeNo, method: method})}).then(function (response) {
            if (response.type === 1 && response.data) window.location.href = response.data;
            else show('\u652f\u4ed8\u8bf7\u6c42\u5df2\u521b\u5efa\uff0c\u8bf7\u6309\u9875\u9762\u63d0\u793a\u5b8c\u6210\u652f\u4ed8\u3002');
        }).catch(function (error) { show(error.message, true); });
    });

    logout.addEventListener('click', function () { clearAuthentication('\u5df2\u9000\u51fa\u767b\u5f55\u3002'); });

    installSharedPanel();
    Promise.all([loadStoreConfig(), loadPlans(), loadGuestConfig()]).then(function () {
        decorateSharedPlans();
        if (auth) setAuthenticated(true);
        else setAuthMode('login');
    });
}());
