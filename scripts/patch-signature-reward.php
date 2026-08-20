<?php

$root = dirname(__DIR__);
$navPath = $root . '/public/theme/signature/assets/static/js/7461.ee1d38eb.js';
$entryPath = $root . '/public/theme/signature/assets/static/js/index.82dc6e81.js';

foreach ([$navPath, $entryPath] as $path) {
    if (!is_file($path) || !is_readable($path)) {
        fwrite(STDERR, "Signature asset is unavailable: {$path}\n");
        exit(1);
    }
}

$nav = file_get_contents($navPath);
if ($nav === false) {
    fwrite(STDERR, "Unable to read Signature navigation bundle.\n");
    exit(1);
}

$navAnchor = 'i&&i.i18nKey!==(null===o||void 0===o?void 0:o.i18nKey)&&e.push(i),e.push({title:"More",path:"/more",name:"More",icon:"IconMore",i18nKey:"more"}),e';
$navReplacement = 'i&&i.i18nKey!==(null===o||void 0===o?void 0:o.i18nKey)&&e.push(i),e.push({title:"Reward",path:"/reward",name:"Reward",icon:"IconGift",i18nKey:"reward"}),e.push({title:"More",path:"/more",name:"More",icon:"IconMore",i18nKey:"more"}),e';
if (strpos($nav, 'path:"/reward"') === false) {
    if (strpos($nav, $navAnchor) === false) {
        fwrite(STDERR, "Signature reward navigation anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($navAnchor, $navReplacement, $nav);
}
if (strpos($nav, 'case"IconGift":return y.A;') === false) {
    $iconAnchor = 'case"IconMore":return Le;case"IconFileText"';
    if (strpos($nav, $iconAnchor) === false) {
        fwrite(STDERR, "Signature reward icon anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($iconAnchor, 'case"IconMore":return Le;case"IconGift":return y.A;case"IconFileText"', $nav);
}

$routeAnchor = '{path:"more",name:"More",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.more",requiresAuth:!0}}';
$routeReplacement = '{path:"reward",name:"Reward",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.reward",requiresAuth:!0,activeNav:"Reward"}},{path:"more",name:"More",component:function(){return n.e(2142).then(n.bind(n,2142))},meta:{titleKey:"menu.more",requiresAuth:!0}}';
if (strpos($nav, '{path:"reward",name:"Reward"') === false) {
    if (strpos($nav, $routeAnchor) === false) {
        fwrite(STDERR, "Signature reward route anchor not found.\n");
        exit(1);
    }
    $nav = str_replace($routeAnchor, $routeReplacement, $nav);
}
$labelAnchor = '(0,s.v_)(e.$t("menu.".concat(t.i18nKey))),1)';
if (strpos($nav, '"Reward"===t.name?"签到娱乐"') === false && strpos($nav, $labelAnchor) !== false) {
    $nav = str_replace($labelAnchor, '(0,s.v_)("Reward"===t.name?"签到娱乐":e.$t("menu.".concat(t.i18nKey))),1)', $nav);
}

if (file_put_contents($navPath, $nav, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write Signature navigation bundle.\n");
    exit(1);
}

$entry = file_get_contents($entryPath);
if ($entry === false) {
    fwrite(STDERR, "Unable to read Signature entry bundle.\n");
    exit(1);
}
if (strpos($entry, 'signature-reward-page') === false) {
    $module = <<<'JS'
(function(){"use strict";var id="signature-reward-page",mark="data-signature-reward-hidden";function api(path,opt){opt=opt||{};opt.headers=Object.assign({"Content-Type":"application/json",Authorization:localStorage.getItem("authorization")||""},opt.headers||{});return fetch("/api/v1"+path,opt).then(function(r){return r.json().then(function(b){if(!r.ok)throw new Error(b.message||b.error||"请求失败");return b})})}function restore(){document.querySelectorAll("["+mark+"]").forEach(function(e){e.removeAttribute(mark);e.style.removeProperty("display")})}function mount(){if(location.pathname!=="/reward"||document.getElementById(id))return;var host=document.querySelector(".static-layout");if(!host)return;host.querySelectorAll(":scope > *").forEach(function(e){if(!e.classList.contains("site-logo")&&!e.classList.contains("top-toolbar")&&!e.classList.contains("slide-tabs-container")){e.setAttribute(mark,"");e.style.display="none"}});var root=document.createElement("section");root.id=id;root.className="reward-page dashboard-card";root.innerHTML='<div class="reward-page-header"><div><h1>签到与娱乐</h1><p>每日签到和娱乐游戏奖励仅在当前订阅周期内有效。</p></div></div><div class="reward-page-grid"><button data-reward="checkin">每日签到</button><button data-reward="dice">丢骰子</button><button data-reward="slots">老虎机</button><button data-reward="poker">炸金花</button></div><div class="reward-page-result" aria-live="polite">选择一个项目开始。</div>';host.appendChild(root);root.querySelectorAll("[data-reward]").forEach(function(btn){btn.onclick=function(){var a=btn.getAttribute("data-reward"),out=root.querySelector(".reward-page-result"),rid="signature-"+a+"-"+Date.now()+"-"+Math.random().toString(36).slice(2,10);root.querySelectorAll("button").forEach(function(x){x.disabled=true});var req=a==="checkin"?api("/user/reward/checkin",{method:"POST"}):a==="dice"?api("/user/game/dice/play",{method:"POST",headers:{"Idempotency-Key":rid}}):a==="slots"?api("/user/game/slots/play",{method:"POST",headers:{"Idempotency-Key":rid}}):api("/user/game/poker/play",{method:"POST",body:JSON.stringify({action:"create",chat_id:"web"})});req.then(function(b){var d=b.data||{};out.textContent=a==="checkin"?"签到成功，获得 "+d.reward_gb+" GB。":a==="dice"?"骰子点数："+d.result+"，获得 "+d.reward_gb+" GB。":a==="slots"?"老虎机结果："+(d.result||[]).join(" | ")+"，获得 "+d.reward_gb+" GB。":d.status==="settled"?"牌局已结算，获得 "+d.reward_gb+" GB。":"已加入牌局，当前玩家 "+d.players+" 人。"}).catch(function(e){out.textContent=e.message}).finally(function(){root.querySelectorAll("button").forEach(function(x){x.disabled=false})})}})}function sync(){var page=document.getElementById(id);if(location.pathname!=="/reward"){if(page)page.remove();restore()}else mount()}var st=document.createElement("style");st.textContent=".reward-page{max-width:1100px;margin:24px auto;padding:24px;color:var(--text-color,#fff)}.reward-page-header h1{margin:0;font-size:24px}.reward-page-header p{margin:8px 0 0;opacity:.7}.reward-page-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:24px}.reward-page-grid button{min-height:86px;border:1px solid var(--border-color,#444);border-radius:10px;background:var(--card-bg,#1d1d1d);color:inherit;font:inherit;cursor:pointer}.reward-page-grid button:hover{border-color:#20b2aa}.reward-page-grid button:disabled{opacity:.55;cursor:wait}.reward-page-result{margin-top:18px;padding:14px;border-radius:8px;background:rgba(255,255,255,.06);min-height:22px}@media(max-width:700px){.reward-page{margin:12px;padding:16px}.reward-page-grid{grid-template-columns:repeat(2,1fr)}}";document.head.appendChild(st);sync();setInterval(sync,300)})();
JS;
    $entry = $module . $entry;
    if (file_put_contents($entryPath, $entry, LOCK_EX) === false) {
        fwrite(STDERR, "Unable to write Signature entry bundle.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Signature reward navigation patched.\n");
