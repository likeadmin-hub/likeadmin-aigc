<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class BlankHeaderActionLayoutContractTest extends TestCase
{
    public function testBlankLayoutActionsStayVisibleAtTheTrailingEdge(): void
    {
        $layout = $this->asset('public/_nuxt/blank.codex-right.js');
        $legacyLayout = $this->asset('public/_nuxt/blank.19bae412.js');
        $styles = $this->asset('public/_nuxt/blank.codex-right.css');
        $legacyStyles = $this->asset('public/_nuxt/blank.7dd5cf3b.css');
        $entry = $this->asset('public/_nuxt/entry.c46691d5.js');
        $aiIndex = $this->asset('public/pc/ai/index.html');

        foreach ([$layout, $legacyLayout] as $compiledLayout) {
            self::assertStringContainsString(
                '/(?:^|\\/)(?:short-drama|aigc_short_drama|aigc_canvas)(?:\\/|$)/.test(v.path)',
                $compiledLayout
            );
            self::assertStringContainsString(
                'class:w?"blank-header-actions is-workspace":"blank-header-actions"',
                $compiledLayout
            );
            self::assertStringContainsString('class:"blank-tutorial-link"', $compiledLayout);
            self::assertStringContainsString('class:"blank-tutorial-label"', $compiledLayout);
            self::assertStringContainsString('K as L', $compiledLayout);
            self::assertStringContainsString('{locale:x}=L()', $compiledLayout);
            self::assertStringContainsString('t==="en"?"Tutorial"', $compiledLayout);
            self::assertStringContainsString('t==="zh-TW"?"\\u65b0\\u624b\\u6559\\u5b78"', $compiledLayout);
            self::assertStringContainsString(':"\\u65b0\\u624b\\u6559\\u7a0b"', $compiledLayout);
            self::assertStringContainsString('title:F', $compiledLayout);
            self::assertStringContainsString('d("span",{class:"blank-tutorial-label"},F)', $compiledLayout);
            self::assertStringNotContainsString('鏂版墜', $compiledLayout);
            self::assertStringNotContainsString('閺傜増', $compiledLayout);
            self::assertStringNotContainsString('class:["blank-header-actions"', $compiledLayout);
            self::assertStringNotContainsString('blank-header-actions,[object Object]', $compiledLayout);
            self::assertStringContainsString('c=document.querySelector(".flow-right")', $compiledLayout);
            self::assertStringContainsString('w=document.querySelector(".app-header__actions")', $compiledLayout);
            self::assertStringContainsString('C=document.querySelector(".topbar-right")', $compiledLayout);
            self::assertStringContainsString(
                'F=document.querySelector(".short-drama-credit-pill.is-fixed")',
                $compiledLayout
            );
            self::assertStringContainsString(
                'M=Array.from(document.querySelectorAll(".membership-modal__close,.membership-pay-mask,.membership-pay-modal,.credit-purchase-mask,.credit-purchase-modal,.credit-agreement-mask,.credit-agreement-modal,.credits-usage-mask,.credits-usage-modal,.el-overlay,.el-overlay-dialog,.el-dialog__wrapper,.v-modal,[role=dialog]")).some',
                $compiledLayout
            );
            self::assertStringContainsString(
                'e.display!=="none"&&e.visibility!=="hidden"&&Number(e.opacity)!==0&&n.width>0&&n.height>0',
                $compiledLayout
            );
            self::assertStringContainsString(
                'A=/\\/ai\\/?$/.test(window.location.pathname)',
                $compiledLayout
            );
            self::assertStringContainsString(
                'R=document.querySelector(".blank-header-actions-host[data-blank-body-host]")',
                $compiledLayout
            );
            self::assertStringContainsString('document.body.appendChild(R)', $compiledLayout);
            self::assertStringContainsString(
                'R.setAttribute("data-blank-body-host","")',
                $compiledLayout
            );
            self::assertStringContainsString(
                'window.innerWidth-B.left+10',
                $compiledLayout
            );
            self::assertStringContainsString(
                'if(M)return a.classList.remove("is-in-flow-topbar","is-in-app-header","is-attached"),!1',
                $compiledLayout
            );
            self::assertStringContainsString('const k=F||C||w||c', $compiledLayout);
            self::assertStringContainsString('R.contains(a)||R.appendChild(a)', $compiledLayout);
            self::assertStringContainsString(
                'if(!k&&!A)return a.classList.remove("is-in-flow-topbar","is-in-app-header","is-attached"),!1',
                $compiledLayout
            );
            self::assertStringContainsString(
                'else R.style.top="18px",R.style.right="24px",R.style.height="36px",R.style.left="96px"',
                $compiledLayout
            );
            self::assertStringContainsString(
                'a.classList.toggle("is-in-flow-topbar",!!c)',
                $compiledLayout
            );
            self::assertStringContainsString(
                'a.classList.toggle("is-in-app-header",!!(w||C||F))',
                $compiledLayout
            );
            self::assertStringContainsString(
                'c=(v.path.startsWith("/ai")||v.path.startsWith("/app/"))&&Number(l.enabled)===1&&b(l.url)',
                $compiledLayout
            );
            self::assertStringContainsString('a.classList.add("is-attached")', $compiledLayout);
            self::assertStringContainsString(
                'window.__blankHeaderActionsInterval=window.setInterval(()=>l(),500)',
                $compiledLayout
            );
            self::assertStringNotContainsString(
                'attributeFilter:["class","style"],childList:!0,subtree:!0',
                $compiledLayout
            );
            self::assertStringContainsString('href="/_nuxt/blank.codex-right.css"', $compiledLayout);
        }

        foreach ([$styles, $legacyStyles] as $compiledStyles) {
            self::assertStringContainsString(
                '.blank-header-actions[data-v-780f342a]{align-items:center;color:#fff;display:none;flex:0 0 auto;gap:10px',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.blank-header-actions.is-attached[data-v-780f342a]{display:flex;pointer-events:auto}',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.blank-header-actions.is-in-app-header[data-v-780f342a],.blank-header-actions.is-in-flow-topbar[data-v-780f342a]{pointer-events:auto;position:static;right:auto;top:auto;z-index:auto}',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.blank-header-actions-host[data-blank-header-actions-host]{align-items:center;box-sizing:border-box;display:flex;flex:0 0 auto;gap:6px;height:36px;justify-content:flex-end;left:96px;min-width:0;max-width:calc(100vw - 120px);overflow:visible;padding:0;pointer-events:none;position:fixed;right:24px;top:18px;z-index:2147483644}',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.app-header>.blank-header-actions-host[data-blank-header-actions-host]{height:36px;left:auto;position:static;right:auto;top:auto;z-index:auto}',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.blank-header-actions[data-v-780f342a] .locale-switcher{align-items:center;background:rgba(15,16,19,.82)',
                $compiledStyles
            );
            self::assertStringContainsString(
                '.blank-tutorial-link[data-v-780f342a]{align-items:center;background:rgba(15,16,19,.82)',
                $compiledStyles
            );
            self::assertStringNotContainsString('position:fixed!important', $compiledStyles);
            self::assertStringNotContainsString('z-index:1200', $compiledStyles);
            self::assertStringNotContainsString(
                '.blank-header-actions.is-workspace[data-v-780f342a]{right:232px}',
                $compiledStyles
            );
        }

        self::assertStringContainsString('import("./blank.codex-right.js")', $entry);
        self::assertStringContainsString('./blank.codex-right.css', $entry);
        self::assertStringNotContainsString('./blank.19bae412.js', $entry);
        self::assertStringNotContainsString('./blank.7dd5cf3b.css', $entry);

        self::assertStringContainsString('/_nuxt/entry.c46691d5.js', $aiIndex);
        self::assertStringContainsString('/_nuxt/blank.codex-right.js', $aiIndex);
        self::assertStringContainsString('/_nuxt/blank.codex-right.css', $aiIndex);
        self::assertStringNotContainsString('id="blank-header-actions-fix"', $aiIndex);
        self::assertStringNotContainsString('position:fixed!important', $aiIndex);
        self::assertStringNotContainsString('/_nuxt/entry.codex-right.js', $aiIndex);
    }

    private function asset(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }
}
