import{D as ElInput,C as ElFormItem,s as ElCard,F as ElForm,i as ElButton,g as ElSwitch}from"./vendor-element-plus-DR4g8u_z.js";
import{_ as MaterialPicker}from"./picker-DTnfOGQp.js";
import{getTutorial,setTutorial}from"./website-Bu78k_2q.js";
import{f as feedback}from"./index-Duk7lJ6Z.js";
import{l as defineComponent,h,aP as withDirectives,as as resolveDirective}from"./vendor-_vue_runtime-core-C0287zFT.js";
import{u as ref,r as reactive}from"./vendor-_vue_reactivity-DHmncv16.js";

const TutorialPage=defineComponent({
    name:"tenantWebTutorial",
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
        return()=>{
            const permission=resolveDirective("perms");
            return h("div",{class:"website-tutorial"},[
                h(ElForm,{ref:formRef,model:form,class:"ls-form","label-width":"120px","scroll-to-error":true},{default:()=>[
                    h(ElCard,{shadow:"never",class:"!border-none"},{default:()=>[
                        h("div",{class:"text-xl font-medium mb-[20px]"},"新手教程"),
                        h(ElFormItem,{label:"启用教程",prop:"enabled"},{default:()=>[
                            h(ElSwitch,{modelValue:form.enabled,"onUpdate:modelValue":value=>form.enabled=value,"active-value":1,"inactive-value":0})
                        ]}),
                        h(ElFormItem,{label:"教程链接",prop:"url",required:form.enabled===1},{default:()=>[
                            h("div",{style:"width:min(560px, 100%)"},[
                                h(ElInput,{modelValue:form.url,"onUpdate:modelValue":value=>form.url=String(value||"").trim(),placeholder:"https://example.com/tutorial",maxlength:"1000","show-word-limit":true})
                            ])
                        ]}),
                        h(ElFormItem,{label:"按钮图标",prop:"icon"},{default:()=>[
                            h("div",null,[
                                h(MaterialPicker,{modelValue:form.icon,"onUpdate:modelValue":value=>form.icon=value,limit:1,width:"72px",height:"72px"}),
                                h("div",{class:"form-tips"},"建议使用透明背景的正方形图片，未设置时显示默认教程标识")
                            ])
                        ]})
                    ]})
                ]}),
                withDirectives(h(ElButton,{type:"primary",onClick:save},{default:()=>"保存"}),[[permission,["setting.web.web_setting/setTutorial"]]])
            ]);
        };
    }
});

export{TutorialPage as default};
