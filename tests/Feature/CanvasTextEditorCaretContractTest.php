<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class CanvasTextEditorCaretContractTest extends TestCase
{
    public function testTextNodeEditingDoesNotRewriteInnerHtmlAfterEachSave(): void
    {
        $bundle = (string) file_get_contents(
            dirname(__DIR__, 2) . '/public/_nuxt/_id_.2fb60286.js'
        );

        self::assertStringNotContainsString('innerHTML:ni.value', $bundle);
        self::assertStringNotContainsString(
            '["data-placeholder","innerHTML","onKeydown"]',
            $bundle
        );
        self::assertStringContainsString(
            'function Lr(){v.node.type!=="text"||ye.value||(Do=Pa(String(ni.value||"")),We.value=!0,Rn.value=null,$n(()=>{const o=tn.value;o&&(o.innerHTML=Do,o.focus({preventScroll:!0})),Cn()}))}',
            $bundle
        );
        self::assertStringContainsString(
            'function Xs(){if(!We.value){So.value=!So.value;return}Rr(),So.value=!So.value,$n(()=>{const o=tn.value;o&&(o.innerHTML=Do,o.focus({preventScroll:!0}),Cn())})}',
            $bundle
        );
    }
}
