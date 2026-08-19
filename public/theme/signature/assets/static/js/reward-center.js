(function () {
  'use strict';
  var rootId = 'signature-reward-center';
  var api = function (path, options) {
    options = options || {};
    options.headers = Object.assign({
      'Content-Type': 'application/json',
      'Authorization': localStorage.getItem('authorization') || ''
    }, options.headers || {});
    return fetch('/api/v1' + path, options).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok) throw new Error(body.message || body.error || '请求失败');
        return body;
      });
    });
  };
  var mount = function () {
    if (!localStorage.getItem('authorization') || document.getElementById(rootId)) return;
    var root = document.createElement('section');
    root.id = rootId;
    root.innerHTML = '<button class="src-launch" type="button" aria-label="签到和娱乐中心">🎁</button>' +
      '<div class="src-panel" hidden><div class="src-head"><strong>签到与娱乐</strong><button class="src-close" type="button" aria-label="关闭">×</button></div>' +
      '<div class="src-status">每日签到可获得 1-10 GB，奖励在当前订阅周期重置时失效。</div>' +
      '<div class="src-actions"><button data-action="checkin" type="button">📅 每日签到</button><button data-action="dice" type="button">🎲 丢骰子</button><button data-action="slots" type="button">🎰 旋转老虎机</button><button data-action="poker" type="button">🃏 加入牌局</button></div>' +
      '<div class="src-result" aria-live="polite">选择一个项目开始。</div></div>';
    document.body.appendChild(root);
    var panel = root.querySelector('.src-panel');
    root.querySelector('.src-launch').onclick = function () { panel.hidden = false; };
    root.querySelector('.src-close').onclick = function () { panel.hidden = true; };
    root.querySelectorAll('[data-action]').forEach(function (button) {
      button.onclick = function () {
        var action = button.getAttribute('data-action');
        var result = root.querySelector('.src-result');
        root.querySelectorAll('[data-action]').forEach(function (item) { item.disabled = true; });
        var requestId = 'signature-' + action + '-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        var request = action === 'checkin'
          ? api('/user/reward/checkin', { method: 'POST' })
          : action === 'dice'
            ? api('/user/game/dice/play', { method: 'POST', headers: { 'Idempotency-Key': requestId } })
            : action === 'slots'
              ? api('/user/game/slots/play', { method: 'POST', headers: { 'Idempotency-Key': requestId } })
              : api('/user/game/poker/play', { method: 'POST', body: JSON.stringify({ action: 'create', chat_id: 'web' }) });
        request.then(function (body) {
          var data = body.data || {};
          if (action === 'checkin') result.textContent = '签到成功，获得 ' + data.reward_gb + ' GB。';
          else if (action === 'dice') result.textContent = '骰子点数：' + data.result + '，获得 ' + data.reward_gb + ' GB。';
          else if (action === 'slots') result.textContent = '老虎机结果：' + (data.result || []).join(' | ') + '，获得 ' + data.reward_gb + ' GB。';
          else result.textContent = data.status === 'settled' ? '牌局已结算，获得 ' + data.reward_gb + ' GB。' : '已加入牌局，当前玩家 ' + data.players + ' 人。';
        }).catch(function (error) { result.textContent = error.message; }).finally(function () {
          root.querySelectorAll('[data-action]').forEach(function (item) { item.disabled = false; });
        });
      };
    });
  };
  var style = document.createElement('style');
  style.textContent = '#signature-reward-center{position:fixed;right:20px;bottom:20px;z-index:9999;font-family:inherit}#signature-reward-center button{font:inherit}#signature-reward-center .src-launch{width:48px;height:48px;border:0;border-radius:50%;background:var(--theme-color,#2563eb);color:#fff;font-size:22px;cursor:pointer;box-shadow:0 8px 24px #0003}#signature-reward-center .src-panel{width:min(360px,calc(100vw - 32px));margin-bottom:12px;padding:16px;border:1px solid var(--border-color,#ddd);border-radius:12px;background:var(--card-bg,#fff);color:var(--text-color,#222);box-shadow:0 12px 32px #0003}.src-head{display:flex;justify-content:space-between;align-items:center}.src-close{border:0;background:transparent;font-size:22px;cursor:pointer}.src-status{margin:12px 0;font-size:13px;line-height:1.5}.src-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.src-actions button{min-height:40px;border:1px solid var(--border-color,#ddd);border-radius:8px;background:transparent;cursor:pointer}.src-actions button:disabled{opacity:.5;cursor:wait}.src-result{min-height:42px;margin-top:12px;padding:10px;border-radius:8px;background:#f3f4f6;font-size:13px}@media(max-width:600px){#signature-reward-center{right:12px;bottom:12px}}';
  document.head.appendChild(style);
  var timer = setInterval(mount, 1000);
  mount();
  window.addEventListener('storage', function (event) { if (event.key === 'authorization') mount(); });
})();
