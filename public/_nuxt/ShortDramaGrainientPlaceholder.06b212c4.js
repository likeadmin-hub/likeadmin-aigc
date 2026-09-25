import{ch as k,g as $,N as j,j as H,r as G,P as J,k as Q,I as ee,o as ae,c as te,a as oe,t as ne,K as le,J as re,Y as N,_ as ue}from"./entry.d4311bbc.js";let g=null,S=0;async function ie(){S++;const f=g||(g=k(()=>import("./index.f944e0b7.js"),[],import.meta.url).then(({Renderer:u})=>new u({webgl:2,alpha:!0,antialias:!1,dpr:1})));let e;try{e=await f}catch(u){throw S--,g===f&&(g=null),u}let m=!1;return{renderer:e,release(){var u;m||(m=!0,S--,S===0&&g===f&&((u=e.gl.getExtension("WEBGL_lose_context"))==null||u.loseContext(),g=null))}}}const ce={class:"short-drama-grainient-placeholder__label"},se=`#version 300 es
in vec2 position;
void main() {
  gl_Position = vec4(position, 0.0, 1.0);
}
`,de=`#version 300 es
precision highp float;
uniform vec2 iResolution;
uniform float iTime;
uniform float uTimeSpeed;
uniform float uColorBalance;
uniform float uWarpStrength;
uniform float uWarpFrequency;
uniform float uWarpSpeed;
uniform float uWarpAmplitude;
uniform float uBlendAngle;
uniform float uBlendSoftness;
uniform float uRotationAmount;
uniform float uNoiseScale;
uniform float uGrainAmount;
uniform float uGrainScale;
uniform float uGrainAnimated;
uniform float uContrast;
uniform float uGamma;
uniform float uSaturation;
uniform vec2 uCenterOffset;
uniform float uZoom;
uniform vec3 uColor1;
uniform vec3 uColor2;
uniform vec3 uColor3;
out vec4 fragColor;
#define S(a,b,t) smoothstep(a,b,t)
mat2 Rot(float a){float s=sin(a),c=cos(a);return mat2(c,-s,s,c);}
vec2 hash(vec2 p){p=vec2(dot(p,vec2(2127.1,81.17)),dot(p,vec2(1269.5,283.37)));return fract(sin(p)*43758.5453);}
float noise(vec2 p){vec2 i=floor(p),f=fract(p),u=f*f*(3.0-2.0*f);float n=mix(mix(dot(-1.0+2.0*hash(i+vec2(0.0,0.0)),f-vec2(0.0,0.0)),dot(-1.0+2.0*hash(i+vec2(1.0,0.0)),f-vec2(1.0,0.0)),u.x),mix(dot(-1.0+2.0*hash(i+vec2(0.0,1.0)),f-vec2(0.0,1.0)),dot(-1.0+2.0*hash(i+vec2(1.0,1.0)),f-vec2(1.0,1.0)),u.x),u.y);return 0.5+0.5*n;}
void mainImage(out vec4 o, vec2 C){
  float t=iTime*uTimeSpeed;
  vec2 uv=C/iResolution.xy;
  float ratio=iResolution.x/iResolution.y;
  vec2 tuv=uv-0.5+uCenterOffset;
  tuv/=max(uZoom,0.001);

  float degree=noise(vec2(t*0.1,tuv.x*tuv.y)*uNoiseScale);
  tuv.y*=1.0/ratio;
  tuv*=Rot(radians((degree-0.5)*uRotationAmount+180.0));
  tuv.y*=ratio;

  float frequency=uWarpFrequency;
  float ws=max(uWarpStrength,0.001);
  float amplitude=uWarpAmplitude/ws;
  float warpTime=t*uWarpSpeed;
  tuv.x+=sin(tuv.y*frequency+warpTime)/amplitude;
  tuv.y+=sin(tuv.x*(frequency*1.5)+warpTime)/(amplitude*0.5);

  vec3 colLav=uColor1;
  vec3 colOrg=uColor2;
  vec3 colDark=uColor3;
  float b=uColorBalance;
  float s=max(uBlendSoftness,0.0);
  mat2 blendRot=Rot(radians(uBlendAngle));
  float blendX=(tuv*blendRot).x;
  float edge0=-0.3-b-s;
  float edge1=0.2-b+s;
  float v0=0.5-b+s;
  float v1=-0.3-b-s;
  vec3 layer1=mix(colDark,colOrg,S(edge0,edge1,blendX));
  vec3 layer2=mix(colOrg,colLav,S(edge0,edge1,blendX));
  vec3 col=mix(layer1,layer2,S(v0,v1,tuv.y));

  vec2 grainUv=uv*max(uGrainScale,0.001);
  if(uGrainAnimated>0.5){grainUv+=vec2(iTime*0.05);}
  float grain=fract(sin(dot(grainUv,vec2(12.9898,78.233)))*43758.5453);
  col+=(grain-0.5)*uGrainAmount;

  col=(col-0.5)*uContrast+0.5;
  float luma=dot(col,vec3(0.2126,0.7152,0.0722));
  col=mix(vec3(luma),col,uSaturation);
  col=pow(max(col,0.0),vec3(1.0/max(uGamma,0.001)));
  col=clamp(col,0.0,1.0);

  o=vec4(col,1.0);
}
void main(){
  vec4 o=vec4(0.0);
  mainImage(o,gl_FragCoord.xy);
  fragColor=o;
}
`,fe=$({__name:"ShortDramaGrainientPlaceholder",props:{label:{default:""},compact:{type:Boolean,default:!1},color1:{default:"#527d7e"},color2:{default:"#163750"},color3:{default:"#07131f"},timeSpeed:{default:1.05},colorBalance:{default:-.01},warpStrength:{default:2.2},warpFrequency:{default:5.4},warpSpeed:{default:3.4},warpAmplitude:{default:30},blendAngle:{default:106},blendSoftness:{default:.23},rotationAmount:{default:490},noiseScale:{default:2.7},grainAmount:{default:.07},grainScale:{default:2.5},grainAnimated:{type:Boolean,default:!1},contrast:{default:1.5},gamma:{default:.9},saturation:{default:1.8},centerX:{default:.13},centerY:{default:-.38},zoom:{default:1.55},webgl:{type:Boolean,default:!0}},setup(f){const e=f,{locale:m}=j(),u=a=>N(a,m.value),X=H(()=>e.label?N(e.label,m.value):u("生成中")),A=G(null),T=G(!1),W=G("0s");let l=null,o=null,r=null,t=null,i=null,v=null,_=!1,I=0,q=!1,c=null,s=null,p=null,d=0,b=!0,h=!0,E=0;const C=a=>{const n=/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(a);return n?new Float32Array([parseInt(n[1],16)/255,parseInt(n[2],16)/255,parseInt(n[3],16)/255]):new Float32Array([1,1,1])},z=()=>{if(!(o!=null&&o.uniforms))return;const a=o.uniforms;a.uTimeSpeed.value=e.timeSpeed,a.uColorBalance.value=e.colorBalance,a.uWarpStrength.value=e.warpStrength,a.uWarpFrequency.value=e.warpFrequency,a.uWarpSpeed.value=e.warpSpeed,a.uWarpAmplitude.value=e.warpAmplitude,a.uBlendAngle.value=e.blendAngle,a.uBlendSoftness.value=e.blendSoftness,a.uRotationAmount.value=e.rotationAmount,a.uNoiseScale.value=e.noiseScale,a.uGrainAmount.value=e.grainAmount,a.uGrainScale.value=e.grainScale,a.uGrainAnimated.value=e.grainAnimated?1:0,a.uContrast.value=e.contrast,a.uGamma.value=e.gamma,a.uSaturation.value=e.saturation,a.uCenterOffset.value=new Float32Array([e.centerX,e.centerY]),a.uZoom.value=e.zoom,a.uColor1.value=C(e.color1),a.uColor2.value=C(e.color2),a.uColor3.value=C(e.color3)},D=()=>{if(!l||!o||!r||!t||!i)return;(l.gl.canvas.width!==t.width||l.gl.canvas.height!==t.height)&&l.setSize(t.width,t.height);const a=o.uniforms.iResolution.value;a[0]=t.width,a[1]=t.height,l.render({scene:r}),i.clearRect(0,0,t.width,t.height),i.drawImage(l.gl.canvas,0,0)},x=()=>{const a=A.value;if(!a||!l||!o||!r||!t)return;const n=a.getBoundingClientRect(),w=Math.min(window.devicePixelRatio||1,640/Math.max(1,n.width,n.height));t.width=Math.max(1,Math.round(n.width*w)),t.height=Math.max(1,Math.round(n.height*w)),D()},R=()=>{d&&(cancelAnimationFrame(d),d=0)},O=a=>{!o||!l||!r||(a-I>=1e3/30&&(o.uniforms.iTime.value=(a-E)*.001,D(),I=a),d=requestAnimationFrame(O))},B=()=>{d||q||!b||!h||!o||(d=requestAnimationFrame(O))},P=()=>{h=!document.hidden,h?B():R()},L=()=>{var a;R(),c==null||c.disconnect(),s==null||s.disconnect(),c=null,s=null,p&&(window.removeEventListener("resize",p),p=null),document.removeEventListener("visibilitychange",P),t!=null&&t.parentNode&&t.parentNode.removeChild(t),(a=r==null?void 0:r.geometry)==null||a.remove(),o==null||o.remove(),v==null||v(),v=null,l=null,o=null,r=null,t=null,i=null};return J(async()=>{const a="__shortDramaGrainientAnimationStart",n=window;n[a]||(n[a]=performance.now());const w=11.6,U=(performance.now()-n[a])/1e3%w;W.value=`${-U}s`;const y=A.value;if(y&&e.webgl)try{const{Program:M,Mesh:V,Triangle:Y}=await k(()=>import("./index.f944e0b7.js"),[],import.meta.url);if(_)return;const F=await ie();if(_){F.release();return}if(l=F.renderer,v=F.release,t=document.createElement("canvas"),i=t.getContext("2d"),!i)throw new Error("Canvas 2D unavailable");t.className="short-drama-grainient-placeholder__canvas",t.style.width="100%",t.style.height="100%",t.style.display="block",y.appendChild(t);const Z=new Y(l.gl);o=new M(l.gl,{vertex:se,fragment:de,uniforms:{iTime:{value:0},iResolution:{value:new Float32Array([1,1])},uTimeSpeed:{value:.25},uColorBalance:{value:0},uWarpStrength:{value:1},uWarpFrequency:{value:5},uWarpSpeed:{value:2},uWarpAmplitude:{value:50},uBlendAngle:{value:0},uBlendSoftness:{value:.05},uRotationAmount:{value:500},uNoiseScale:{value:2},uGrainAmount:{value:.1},uGrainScale:{value:2},uGrainAnimated:{value:0},uContrast:{value:1.5},uGamma:{value:1},uSaturation:{value:1},uCenterOffset:{value:new Float32Array([0,0])},uZoom:{value:.9},uColor1:{value:new Float32Array([1,1,1])},uColor2:{value:new Float32Array([1,1,1])},uColor3:{value:new Float32Array([1,1,1])}}}),r=new V(l.gl,{geometry:Z,program:o}),z(),typeof ResizeObserver<"u"?(c=new ResizeObserver(x),c.observe(y)):(p=x,window.addEventListener("resize",p)),x(),E=n[a],q=window.matchMedia("(prefers-reduced-motion: reduce)").matches,h=!document.hidden,document.addEventListener("visibilitychange",P),typeof IntersectionObserver<"u"&&(s=new IntersectionObserver(([K])=>{b=K.isIntersecting,b?B():R()},{threshold:0}),s.observe(y)),B()}catch{T.value=!0,L()}}),Q(()=>[e.timeSpeed,e.colorBalance,e.warpStrength,e.warpFrequency,e.warpSpeed,e.warpAmplitude,e.blendAngle,e.blendSoftness,e.rotationAmount,e.noiseScale,e.grainAmount,e.grainScale,e.grainAnimated,e.contrast,e.gamma,e.saturation,e.centerX,e.centerY,e.zoom,e.color1,e.color2,e.color3],z),ee(()=>{_=!0,L()}),(a,n)=>(ae(),te("div",{ref_key:"containerRef",ref:A,class:le(["short-drama-grainient-placeholder",{"is-compact":a.compact,"is-fallback":T.value}]),style:re({"--grainient-animation-delay":W.value})},[oe("div",ce,ne(X.value),1)],6))}});const ve=ue(fe,[["__scopeId","data-v-4bf80d39"]]);export{ve as S};
