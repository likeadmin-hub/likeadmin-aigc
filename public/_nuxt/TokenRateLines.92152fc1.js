import{g as S,o as b,c as h,Y as j,t as O,X as _,_ as x}from"./entry.e0737555.js";function N(t){for(const r of Object.values((t==null?void 0:t.examples)||{}))if(r&&Object.prototype.hasOwnProperty.call(r,"value"))return{available:!0,value:r.value};return t&&Object.prototype.hasOwnProperty.call(t,"example")?{available:!0,value:t.example}:{available:!1}}function T(t={}){const r=Object.entries(t),l=[...r.filter(([n])=>/^2\d\d$/.test(n)),...r.filter(([n])=>!/^2\d\d$/.test(n))];for(const[n,a]of l)for(const[i,c]of Object.entries((a==null?void 0:a.content)||{})){const o=N(c);if(!o.available)continue;const p=typeof o.value=="string"&&!/json/i.test(i)?o.value:JSON.stringify(o.value,null,2);return{available:!0,status:n,contentType:i,text:p}}return{available:!1,status:"",contentType:"",text:""}}function v(t){var r;return Object.entries(((r=t==null?void 0:t.requestBody)==null?void 0:r.content)||{}).map(([l,n])=>({type:l,...n}))[0]||{type:"application/json",schema:{},example:{}}}function k(t,r,l){var y;if(!t)return"";const n=v(t),a=n.type==="multipart/form-data",i=t.method==="GET"?Object.fromEntries((t.parameters||[]).filter(e=>{var s;return e.in==="query"&&(e.example!==void 0||((s=e.schema)==null?void 0:s.default)!==void 0)}).map(e=>[e.name,e.example??e.schema.default])):n.example||{},c={Authorization:"Bearer <API_KEY>"};t["x-billable"]&&(c["Idempotency-Key"]="<IDEMPOTENCY_KEY>"),!a&&t.method!=="GET"&&(c["Content-Type"]=n.type);const o=r+t.path,p=JSON.stringify(i,null,2),u=Object.entries(((y=n.schema)==null?void 0:y.properties)||{}).filter(([,e])=>{var s;return e.format==="binary"||((s=e.items)==null?void 0:s.format)==="binary"}).map(([e])=>e),d=u.flatMap(e=>{var s;return((s=n.schema.properties[e].items)==null?void 0:s.format)==="binary"?Array.from({length:Math.max(1,Array.isArray(i[e])?i[e].length:1)},($,g)=>`${e}[${g}]`):[e]});if(l==="Python")return`import requests

url = ${JSON.stringify(o)}
headers = ${JSON.stringify(c,null,2)}
params = ${JSON.stringify(p)}
import json
params = json.loads(params)${a?`
for name in `+JSON.stringify(u)+`:
    params.pop(name, None)`:""}
${a?`from contextlib import ExitStack
with ExitStack() as stack:
    files = [`+d.map(e=>"("+JSON.stringify(e)+', stack.enter_context(open("sample.bin", "rb")))').join(", ")+`]
    params = {name: json.dumps(value) if isinstance(value, (dict, list)) else str(value) for name, value in params.items()}
    response = requests.request(`+JSON.stringify(t.method)+`, url, headers=headers, data=params, files=files, timeout=180)
    print(response.status_code, response.text)`:"response = requests.request("+JSON.stringify(t.method)+", url, headers=headers, "+(t.method==="GET"?"params":"json")+`=params, timeout=180)
print(response.status_code, response.text)`}`;if(l==="JavaScript")return`${a?`import { readFile } from "node:fs/promises";
const body = new FormData();
`+Object.entries(i).filter(([e])=>!u.includes(e)).map(([e,s])=>"body.append("+JSON.stringify(e)+", "+JSON.stringify(typeof s=="object"?JSON.stringify(s):String(s))+");").join(`
`)+`
`+d.map(e=>"body.append("+JSON.stringify(e)+', new Blob([await readFile("sample.bin")]), "sample.bin");').join(`
`)+`
`:""}const url = new URL(${JSON.stringify(o)});
${t.method==="GET"?"const params = "+p+`;
for (const [name, value] of Object.entries(params)) url.searchParams.set(name, typeof value === "object" ? JSON.stringify(value) : String(value));
`:""}const response = await fetch(url, {
  method: ${JSON.stringify(t.method)},
  headers: ${JSON.stringify(c,null,2)},${t.method!=="GET"?`
  body: `+(a?"body":"JSON.stringify("+p+")")+",":""}
});
console.log(response.status, await response.text());`;const m=e=>"'"+String(e).replace(/'/g,"'\\''")+"'",f=[`curl --request ${t.method} ${m(o)}`,...Object.entries(c).map(([e,s])=>"--header "+m(e+": "+s))];return t.method==="GET"?f.push("--get",...Object.entries(i).map(([e,s])=>"--data-urlencode "+m(e+"="+(typeof s=="object"?JSON.stringify(s):String(s))))):a?f.push(...Object.entries(i).filter(([e])=>!u.includes(e)).map(([e,s])=>"--form-string "+m(e+"="+(typeof s=="object"?JSON.stringify(s):String(s)))),...d.map(e=>"--form "+m(e+"=@sample.bin"))):f.push("--data "+m(p)),f.join(` \\
  `)}const J=S({__name:"TokenRateLines",props:{rates:{},subtract:{},labels:{default:()=>({input:"输入",cached_input:"缓存输入",output:"输出"})}},setup(t){return(r,l)=>(b(!0),h(_,null,j(r.labels,(n,a)=>{var i;return b(),h("span",{class:"token-rate",key:a},O(n)+" "+O(Number((Number(r.rates[a])-Number(((i=r.subtract)==null?void 0:i[a])??0)).toFixed(6))),1)}),128))}});const w=x(J,[["__scopeId","data-v-c6dde0e7"]]);export{w as T,T as a,k as c,v as r};
