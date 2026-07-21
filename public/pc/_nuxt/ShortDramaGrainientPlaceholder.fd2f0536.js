import{f as O,r as A,G as E,a$ as L,j as M,C as N,o as P,c as k,a as X,t as U,M as V,D as Z}from"./entry.c8110629.js";import{_ as Y}from"./_plugin-vue_export-helper.c27b6911.js";const $={class:"short-drama-grainient-placeholder__label"},H=`#version 300 es
in vec2 position;
void main() {
  gl_Position = vec4(position, 0.0, 1.0);
}
`,j=`#version 300 es
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
`,K=O({__name:"ShortDramaGrainientPlaceholder",props:{label:{default:"生成中"},compact:{type:Boolean,default:!1},color1:{default:"#526f73"},color2:{default:"#1e3248"},color3:{default:"#07131f"},timeSpeed:{default:1.05},colorBalance:{default:-.01},warpStrength:{default:2.2},warpFrequency:{default:5.4},warpSpeed:{default:3.4},warpAmplitude:{default:30},blendAngle:{default:106},blendSoftness:{default:.23},rotationAmount:{default:490},noiseScale:{default:2.7},grainAmount:{default:.07},grainScale:{default:2.5},grainAnimated:{type:Boolean,default:!1},contrast:{default:1.38},gamma:{default:.9},saturation:{default:1.2},centerX:{default:.13},centerY:{default:-.38},zoom:{default:1.55},webgl:{type:Boolean,default:!0}},setup(W){const e=W,d=A(null),b=A(!1),_=A("0s");let t=null,n=null,u=null,l=null,i=null,c=null,f=null,s=0,v=!0,m=!0,C=0;const p=a=>{const o=/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(a);return o?new Float32Array([parseInt(o[1],16)/255,parseInt(o[2],16)/255,parseInt(o[3],16)/255]):new Float32Array([1,1,1])},x=()=>{if(!(n!=null&&n.uniforms))return;const a=n.uniforms;a.uTimeSpeed.value=e.timeSpeed,a.uColorBalance.value=e.colorBalance,a.uWarpStrength.value=e.warpStrength,a.uWarpFrequency.value=e.warpFrequency,a.uWarpSpeed.value=e.warpSpeed,a.uWarpAmplitude.value=e.warpAmplitude,a.uBlendAngle.value=e.blendAngle,a.uBlendSoftness.value=e.blendSoftness,a.uRotationAmount.value=e.rotationAmount,a.uNoiseScale.value=e.noiseScale,a.uGrainAmount.value=e.grainAmount,a.uGrainScale.value=e.grainScale,a.uGrainAnimated.value=e.grainAnimated?1:0,a.uContrast.value=e.contrast,a.uGamma.value=e.gamma,a.uSaturation.value=e.saturation,a.uCenterOffset.value=new Float32Array([e.centerX,e.centerY]),a.uZoom.value=e.zoom,a.uColor1.value=p(e.color1),a.uColor2.value=p(e.color2),a.uColor3.value=p(e.color3)},g=()=>{const a=d.value;if(!a||!t||!n||!u)return;const o=a.getBoundingClientRect(),y=Math.max(1,Math.floor(o.width)),w=Math.max(1,Math.floor(o.height));t.setSize(y,w);const r=n.uniforms.iResolution.value;r[0]=t.gl.drawingBufferWidth,r[1]=t.gl.drawingBufferHeight,t.render({scene:u})},h=()=>{s&&(cancelAnimationFrame(s),s=0)},B=a=>{!n||!t||!u||(n.uniforms.iTime.value=(a-C)*.001,t.render({scene:u}),s=requestAnimationFrame(B))},S=()=>{s||!v||!m||!n||(s=requestAnimationFrame(B))},R=()=>{m=!document.hidden,m?S():h()},G=()=>{var o;h(),i==null||i.disconnect(),c==null||c.disconnect(),i=null,c=null,f&&(window.removeEventListener("resize",f),f=null),document.removeEventListener("visibilitychange",R),l!=null&&l.parentNode&&l.parentNode.removeChild(l);const a=t==null?void 0:t.gl;a&&((o=a.getExtension("WEBGL_lose_context"))==null||o.loseContext()),t=null,n=null,u=null,l=null};return E(async()=>{const a="__shortDramaGrainientAnimationStart",o=window;o[a]||(o[a]=performance.now());const y=e.webgl?5.8:3.4,w=(performance.now()-o[a])/1e3%y;_.value=`${-w}s`;const r=d.value;if(r&&e.webgl)try{const{Renderer:F,Program:T,Mesh:q,Triangle:z}=await L(()=>import("./index.f944e0b7.js"),[],import.meta.url);t=new F({webgl:2,alpha:!0,antialias:!1,dpr:Math.min(window.devicePixelRatio||1,2)}),l=t.gl.canvas,l.className="short-drama-grainient-placeholder__canvas",l.style.width="100%",l.style.height="100%",l.style.display="block",r.appendChild(l);const D=new z(t.gl);n=new T(t.gl,{vertex:H,fragment:j,uniforms:{iTime:{value:0},iResolution:{value:new Float32Array([1,1])},uTimeSpeed:{value:.25},uColorBalance:{value:0},uWarpStrength:{value:1},uWarpFrequency:{value:5},uWarpSpeed:{value:2},uWarpAmplitude:{value:50},uBlendAngle:{value:0},uBlendSoftness:{value:.05},uRotationAmount:{value:500},uNoiseScale:{value:2},uGrainAmount:{value:.1},uGrainScale:{value:2},uGrainAnimated:{value:0},uContrast:{value:1.5},uGamma:{value:1},uSaturation:{value:1},uCenterOffset:{value:new Float32Array([0,0])},uZoom:{value:.9},uColor1:{value:new Float32Array([1,1,1])},uColor2:{value:new Float32Array([1,1,1])},uColor3:{value:new Float32Array([1,1,1])}}}),u=new q(t.gl,{geometry:D,program:n}),x(),typeof ResizeObserver<"u"?(i=new ResizeObserver(g),i.observe(r)):(f=g,window.addEventListener("resize",f)),g(),C=performance.now(),m=!document.hidden,document.addEventListener("visibilitychange",R),typeof IntersectionObserver<"u"&&(c=new IntersectionObserver(([I])=>{v=I.isIntersecting,v?S():h()},{threshold:0}),c.observe(r)),S()}catch{b.value=!0,G()}}),M(()=>[e.timeSpeed,e.colorBalance,e.warpStrength,e.warpFrequency,e.warpSpeed,e.warpAmplitude,e.blendAngle,e.blendSoftness,e.rotationAmount,e.noiseScale,e.grainAmount,e.grainScale,e.grainAnimated,e.contrast,e.gamma,e.saturation,e.centerX,e.centerY,e.zoom,e.color1,e.color2,e.color3],x),N(G),(a,o)=>(P(),k("div",{ref_key:"containerRef",ref:d,class:V(["short-drama-grainient-placeholder",{"is-compact":a.compact,"is-fallback":b.value}]),style:Z({"--grainient-animation-delay":_.value})},[X("div",$,U(a.label),1)],6))}});const ee=Y(K,[["__scopeId","data-v-93949aa8"]]);export{ee as S};
