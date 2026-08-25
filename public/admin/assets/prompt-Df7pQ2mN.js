import{D as ElInput,i as ElButton,$ as ElAlert,K as ElTag,E as ElMessage}from"./vendor-element-plus-DR4g8u_z.js";
import{l as defineComponent,i as h}from"./vendor-_vue_runtime-core-C0287zFT.js";
import{u as ref,r as reactive}from"./vendor-_vue_reactivity-DHmncv16.js";
import{f as getConfig,s as saveConfig}from"./api-Brft_nEB.js";

const SCRIPT_FIELDS=new Set([
  "script_system_prompt",
  "script_prompt_template",
  "multi_episode_script_system_prompt",
  "multi_episode_script_prompt_template"
]);

const PromptPage=defineComponent({
  name:"tenant-aigc-short-drama-prompt",
  setup(){
    const loading=ref(false);
    const saving=ref(false);
    const activeGroup=ref("general");
    const groups=ref([]);
    const values=reactive({});

    const load=async()=>{
      loading.value=true;
      try{
        const config=await getConfig();
        groups.value=Array.isArray(config==null?void 0:config.prompt_config_definitions)?config.prompt_config_definitions:[];
        const configured=config&&typeof config.prompt_config_values==="object"?config.prompt_config_values:{};
        for(const group of groups.value){
          for(const item of Array.isArray(group.items)?group.items:[]){
            values[item.key]=String(configured[item.key]??item.default??"");
          }
        }
        if(!groups.value.some(group=>group.key===activeGroup.value)&&groups.value.length){
          activeGroup.value=groups.value[0].key;
        }
      }finally{
        loading.value=false;
      }
    };

    const resetItem=item=>{
      values[item.key]=String(item.default??"");
    };

    const save=async()=>{
      saving.value=true;
      try{
        const promptConfig={};
        const payload={prompt_config:promptConfig};
        for(const group of groups.value){
          for(const item of Array.isArray(group.items)?group.items:[]){
            const value=String(values[item.key]??"");
            if(SCRIPT_FIELDS.has(item.key)){
              payload[item.key]=value;
            }else{
              promptConfig[item.key]=value;
            }
          }
        }
        await saveConfig(payload);
        ElMessage.success("保存成功");
        await load();
      }finally{
        saving.value=false;
      }
    };

    const currentGroup=()=>groups.value.find(group=>group.key===activeGroup.value)||groups.value[0];
    const renderVariables=item=>Array.isArray(item.variables)&&item.variables.length
      ?h("div",{class:"prompt-variable-list"},[
        h("span",{class:"prompt-variable-label"},"可用变量"),
        ...item.variables.map(variable=>h(ElTag,{key:variable,size:"small",effect:"plain"},{default:()=>variable}))
      ]):null;
    const renderItem=item=>h("section",{key:item.key,class:"prompt-editor"},[
      h("div",{class:"prompt-editor__heading"},[
        h("div",null,[
          h("h3",null,item.label),
          h("p",null,item.description||"")
        ]),
        h(ElButton,{plain:true,onClick:()=>resetItem(item)},{default:()=>"恢复默认"})
      ]),
      renderVariables(item),
      h(ElInput,{
        modelValue:values[item.key],
        "onUpdate:modelValue":value=>values[item.key]=value,
        type:"textarea",
        rows:item.key.includes("system_prompt")?18:10,
        resize:"vertical",
        maxlength:60000,
        showWordLimit:true,
        placeholder:`请输入${item.label}`
      })
    ]);

    load();
    return()=>{
      const group=currentGroup();
      return h("div",{class:"short-drama-prompt-page",loading:loading.value},[
        h("header",{class:"prompt-page-header"},[
          h("div",null,[
            h("h2",null,"短剧提示词配置"),
            h("p",null,"集中管理剧本策划、主体、三视图、场景、分镜图片与视频的提交提示词。")
          ]),
          h(ElButton,{type:"primary",loading:saving.value,onClick:save},{default:()=>"保存"})
        ]),
        h(ElAlert,{
          class:"prompt-page-alert",
          type:"warning",
          showIcon:true,
          closable:false,
          title:"剧本模板请保留 {{default_prompt}}，否则会覆盖单集/多集的结构契约；图片和视频模板请保留 {{prompt}}，用于承接系统生成的完整基础提示词。恢复默认后不会改变现有生成效果。"
        }),
        h("div",{class:"prompt-workspace"},[
          h("nav",{class:"prompt-group-nav"},groups.value.map(item=>h("button",{
            key:item.key,
            type:"button",
            class:["prompt-group-nav__item",item.key===activeGroup.value?"is-active":""],
            onClick:()=>activeGroup.value=item.key
          },[
            h("span",null,item.label),
            h("small",null,String(Array.isArray(item.items)?item.items.length:0))
          ]))),
          h("main",{class:"prompt-group-content"},group?[
            h("div",{class:"prompt-group-heading"},[
              h("h2",null,group.label),
              h("p",null,group.description||"")
            ]),
            ...(Array.isArray(group.items)?group.items.map(renderItem):[])
          ]:[])
        ])
      ]);
    };
  }
});

export{PromptPage as default};
