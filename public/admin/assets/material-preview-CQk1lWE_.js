function f(n,e=[],u=r=>r){const r=String(n||"");if(!r)return"";if(/^https?:\/\//i.test(r))return r;const t=e.find(i=>i.uri===r&&i.url);return t?t.url:u(r)}export{f as m};
