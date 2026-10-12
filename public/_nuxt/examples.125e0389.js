function h(t){for(const n of Object.values((t==null?void 0:t.examples)||{}))if(n&&Object.prototype.hasOwnProperty.call(n,"value"))return{available:!0,value:n.value};return t&&Object.prototype.hasOwnProperty.call(t,"example")?{available:!0,value:t.example}:{available:!1}}function j(t={}){const n=Object.entries(t),m=[...n.filter(([r])=>/^2\d\d$/.test(r)),...n.filter(([r])=>!/^2\d\d$/.test(r))];for(const[r,a]of m)for(const[i,l]of Object.entries((a==null?void 0:a.content)||{})){const o=h(l);if(!o.available)continue;const c=typeof o.value=="string"&&!/json/i.test(i)?o.value:JSON.stringify(o.value,null,2);return{available:!0,status:r,contentType:i,text:c}}return{available:!1,status:"",contentType:"",text:""}}function O(t){var n;return Object.entries(((n=t==null?void 0:t.requestBody)==null?void 0:n.content)||{}).map(([m,r])=>({type:m,...r}))[0]||{type:"application/json",schema:{},example:{}}}function S(t,n,m){var d;if(!t)return"";const r=O(t),a=r.type==="multipart/form-data",i=t.method==="GET"?Object.fromEntries((t.parameters||[]).filter(e=>{var s;return e.in==="query"&&(e.example!==void 0||((s=e.schema)==null?void 0:s.default)!==void 0)}).map(e=>[e.name,e.example??e.schema.default])):r.example||{},l={Authorization:"Bearer <API_KEY>"};t["x-billable"]&&(l["Idempotency-Key"]="<IDEMPOTENCY_KEY>"),!a&&t.method!=="GET"&&(l["Content-Type"]=r.type);const o=n+t.path,c=JSON.stringify(i,null,2),p=Object.entries(((d=r.schema)==null?void 0:d.properties)||{}).filter(([,e])=>{var s;return e.format==="binary"||((s=e.items)==null?void 0:s.format)==="binary"}).map(([e])=>e),y=p.flatMap(e=>{var s;return((s=r.schema.properties[e].items)==null?void 0:s.format)==="binary"?Array.from({length:Math.max(1,Array.isArray(i[e])?i[e].length:1)},(g,b)=>`${e}[${b}]`):[e]});if(m==="Python")return`import requests

url = ${JSON.stringify(o)}
headers = ${JSON.stringify(l,null,2)}
params = ${JSON.stringify(c)}
import json
params = json.loads(params)${a?`
for name in `+JSON.stringify(p)+`:
    params.pop(name, None)`:""}
${a?`from contextlib import ExitStack
with ExitStack() as stack:
    files = [`+y.map(e=>"("+JSON.stringify(e)+', stack.enter_context(open("sample.bin", "rb")))').join(", ")+`]
    params = {name: json.dumps(value) if isinstance(value, (dict, list)) else str(value) for name, value in params.items()}
    response = requests.request(`+JSON.stringify(t.method)+`, url, headers=headers, data=params, files=files, timeout=180)
    print(response.status_code, response.text)`:"response = requests.request("+JSON.stringify(t.method)+", url, headers=headers, "+(t.method==="GET"?"params":"json")+`=params, timeout=180)
print(response.status_code, response.text)`}`;if(m==="JavaScript")return`${a?`import { readFile } from "node:fs/promises";
const body = new FormData();
`+Object.entries(i).filter(([e])=>!p.includes(e)).map(([e,s])=>"body.append("+JSON.stringify(e)+", "+JSON.stringify(typeof s=="object"?JSON.stringify(s):String(s))+");").join(`
`)+`
`+y.map(e=>"body.append("+JSON.stringify(e)+', new Blob([await readFile("sample.bin")]), "sample.bin");').join(`
`)+`
`:""}const url = new URL(${JSON.stringify(o)});
${t.method==="GET"?"const params = "+c+`;
for (const [name, value] of Object.entries(params)) url.searchParams.set(name, typeof value === "object" ? JSON.stringify(value) : String(value));
`:""}const response = await fetch(url, {
  method: ${JSON.stringify(t.method)},
  headers: ${JSON.stringify(l,null,2)},${t.method!=="GET"?`
  body: `+(a?"body":"JSON.stringify("+c+")")+",":""}
});
console.log(response.status, await response.text());`;const f=e=>"'"+String(e).replace(/'/g,"'\\''")+"'",u=[`curl --request ${t.method} ${f(o)}`,...Object.entries(l).map(([e,s])=>"--header "+f(e+": "+s))];return t.method==="GET"?u.push("--get",...Object.entries(i).map(([e,s])=>"--data-urlencode "+f(e+"="+(typeof s=="object"?JSON.stringify(s):String(s))))):a?u.push(...Object.entries(i).filter(([e])=>!p.includes(e)).map(([e,s])=>"--form-string "+f(e+"="+(typeof s=="object"?JSON.stringify(s):String(s)))),...y.map(e=>"--form "+f(e+"=@sample.bin"))):u.push("--data "+f(c)),u.join(` \\
  `)}export{j as a,S as c,O as r};
