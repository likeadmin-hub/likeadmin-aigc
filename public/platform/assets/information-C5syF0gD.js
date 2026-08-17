import TutorialPage from"./tutorial-CodexPlatformA1.js";
import InformationPage from"./information-original-CodexPlatformA1.js";
import{d as defineComponent,o as openBlock,I as createBlock}from"./vendor-_vue_runtime-core-Df2LCT_I.js";
import{u as useRoute}from"./vendor-vue-router-BM_Mix3u.js";

const WebsiteSettingsPage=defineComponent({
    name:"platformWebsiteSettingsPage",
    setup(){
        const route=useRoute();
        const isTutorial=()=>route.meta.sourceMenuKey==="core_platform_tutorial"||route.path.split("/").filter(Boolean).at(-1)==="tutorial";
        return()=>(openBlock(),createBlock(isTutorial()?TutorialPage:InformationPage));
    }
});

export{WebsiteSettingsPage as default};
