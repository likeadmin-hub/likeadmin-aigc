import TutorialPage from"./tutorial-CodexTenantA1.js";
import InformationPage from"./information-original-CodexTenantA1.js";
import{l as defineComponent,ak as openBlock,G as createBlock}from"./vendor-_vue_runtime-core-C0287zFT.js";
import{u as useRoute}from"./vendor-vue-router-DLmn0Qea.js";

const WebsiteSettingsPage=defineComponent({
    name:"tenantWebsiteSettingsPage",
    setup(){
        const route=useRoute();
        const isTutorial=()=>route.meta.sourceMenuKey==="core_tenant_tutorial"||route.path.split("/").filter(Boolean).at(-1)==="tutorial";
        return()=>(openBlock(),createBlock(isTutorial()?TutorialPage:InformationPage));
    }
});

export{WebsiteSettingsPage as default};
