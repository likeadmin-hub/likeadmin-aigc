import{D as ElInput,F as ElFormItem,r as ElCard,G as ElForm,g as ElButton,e as ElSwitch}from"./vendor-element-plus-BZpdRwuY.js";
import{_ as MaterialPicker}from"./picker-CaCIrFNj.js";
import{getTutorial,setTutorial}from"./website-CFwkauZ0.js";
import{f as feedback}from"./index-DH0WGi9f.js";
import{d as defineComponent,$ as resolveDirective,o as openBlock,c as createElementBlock,j as createVNode,J as withCtx,a as createElementVNode,N as createTextVNode,K as withDirectives,I as createBlock}from"./vendor-_vue_runtime-core-Df2LCT_I.js";
import{v as unref,r as ref,n as reactive}from"./vendor-_vue_reactivity-CQZkEpgR.js";

const root={class:"website-tutorial"};
const field={style:"width:min(560px, 100%)"};

const TutorialPage=defineComponent({
    name:"platformWebTutorial",
    setup(){
        const formRef=ref();
        const form=reactive({enabled:0,url:"",icon:""});
        const load=async()=>{
            const data=await getTutorial();
            Object.assign(form,{enabled:Number(data.enabled)===1?1:0,url:data.url||"",icon:data.icon||""});
        };
        const save=async()=>{
            form.url=String(form.url||"").trim();
            if(form.enabled===1&&!form.url){
                feedback.msgError("启用新手教程后必须填写教程链接");
                return;
            }
            if(form.url){
                try{
                    const parsed=new URL(form.url);
                    if(!["http:","https:"].includes(parsed.protocol))throw new Error("protocol");
                }catch{
                    feedback.msgError("请输入有效的 http 或 https 教程链接");
                    return;
                }
            }
            await setTutorial({...form});
            feedback.msgSuccess("保存成功");
            await load();
        };
        load();
        return(_ctx,cache)=>{
            const permission=resolveDirective("perms");
            return(openBlock(),createElementBlock("div",root,[
                createVNode(ElForm,{ref_key:"formRef",ref:formRef,model:unref(form),class:"ls-form","label-width":"120px","scroll-to-error":""},{default:withCtx(()=>[
                    createVNode(ElCard,{shadow:"never",class:"!border-none"},{default:withCtx(()=>[
                        createElementVNode("div",{class:"text-xl font-medium mb-[20px]"},"新手教程"),
                        createVNode(ElFormItem,{label:"启用教程",prop:"enabled"},{default:withCtx(()=>[
                            createVNode(ElSwitch,{modelValue:unref(form).enabled,"onUpdate:modelValue":cache[0]||(value=>unref(form).enabled=value),"active-value":1,"inactive-value":0},null,8,["modelValue"])
                        ]),_:1}),
                        createVNode(ElFormItem,{label:"教程链接",prop:"url",required:unref(form).enabled===1},{default:withCtx(()=>[
                            createElementVNode("div",field,[
                                createVNode(ElInput,{modelValue:unref(form).url,"onUpdate:modelValue":cache[1]||(value=>unref(form).url=value),modelModifiers:{trim:true},placeholder:"https://example.com/tutorial",maxlength:"1000","show-word-limit":""},null,8,["modelValue"])
                            ])
                        ]),_:1},8,["required"]),
                        createVNode(ElFormItem,{label:"按钮图标",prop:"icon"},{default:withCtx(()=>[
                            createElementVNode("div",null,[
                                createVNode(MaterialPicker,{modelValue:unref(form).icon,"onUpdate:modelValue":cache[2]||(value=>unref(form).icon=value),limit:1,width:"72px",height:"72px"},null,8,["modelValue"]),
                                createElementVNode("div",{class:"form-tips"},"建议使用透明背景的正方形图片，未设置时显示默认教程标识")
                            ])
                        ]),_:1})
                    ]),_:1})
                ]),_:1},8,["model"]),
                withDirectives((openBlock(),createBlock(ElButton,{type:"primary",onClick:save},{default:withCtx(()=>[createTextVNode("保存")]),_:1})),[[permission,["setting.web.web_setting/setTutorial"]]])
            ]));
        };
    }
});

export{TutorialPage as default};
