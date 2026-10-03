// 给 signature 产物的响应拦截器打补丁：
// 401/403 → 登出并跳登录页 这个判断太宽 —— 任何接口返回 401/403 都会把用户登出。
// 登录接口自己返回 401（凭据不对）时也会命中，等于「输错一次密码就被登出」。
// 补丁：/passport/* 是未登录入口区，那里的 401/403 只代表「这次没通过」，不代表会话失效。
//
// 锚点是**单行内**的子串（不含换行），所以不受 LF/CRLF 影响。

const fs = require('fs');
const path = require('path');

const file = path.resolve(__dirname, '../public/theme/signature/assets/static/js/7461.03f18c3f.js');

const FROM = '401!==r&&403!==r||((0,hr.Wf)(),window.location.hash.includes("/login")||(window.location.hash="/login"));';
const TO = '401!==r&&403!==r||/\\/passport\\//.test((e.config&&e.config.url)||"")||((0,hr.Wf)(),window.location.hash.includes("/login")||(window.location.hash="/login"));';

const source = fs.readFileSync(file, 'utf8');

function countOccurrences(haystack, needle) {
    let count = 0;
    let index = haystack.indexOf(needle);
    while (index !== -1) {
        count += 1;
        index = haystack.indexOf(needle, index + needle.length);
    }
    return count;
}

const fromCount = countOccurrences(source, FROM);
const toCount = countOccurrences(source, TO);

console.log('锚点出现次数 :', fromCount);
console.log('已存在新代码 :', toCount);

if (fromCount === 0 && toCount === 1) {
    console.log('结果: 已经打过补丁，跳过');
    process.exit(0);
}
if (fromCount !== 1) {
    console.error('结果: 锚点必须恰好出现 1 次，实际 ' + fromCount + ' 次 —— 中止，未改动文件');
    process.exit(1);
}

const patched = source.replace(FROM, TO);

// 自检：改后体积差必须正好等于替换串长度差
const expectedDelta = TO.length - FROM.length;
if (patched.length - source.length !== expectedDelta) {
    console.error('结果: 体积变化不符，中止');
    process.exit(1);
}

fs.writeFileSync(file, patched);

// 终检
const after = fs.readFileSync(file, 'utf8');
console.log('替换后旧串残留 :', countOccurrences(after, FROM));
console.log('替换后新串存在 :', countOccurrences(after, TO));
console.log('体积变化       :', after.length - source.length, '(预期', expectedDelta + ')');
console.log('结果: 补丁已写入');
