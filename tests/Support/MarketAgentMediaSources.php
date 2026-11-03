<?php
/** Synthetic bytes shared by owned HTTP fixtures; no user files or provider calls. */
function marketAgentMediaSources($f): void
{
    // Three real PDF pages exercise two decoder batches, including page rasters.
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'<< /Type /Pages /Kids [4 0 R 6 0 R 8 0 R] /Count 3 >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
    foreach([1,2,3]as$n){$id=2+2*$n;$stream='BT /F1 18 Tf 60 720 Td (Synthetic page '.$n.'. Written consent required.) Tj ET';$objects[$id]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents '.($id+1).' 0 R >>';$objects[$id+1]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream";}
    ksort($objects);$pdf="%PDF-1.4\n";$offsets=[];foreach($objects as$id=>$object){$offsets[]=strlen($pdf);$pdf.=$id." 0 obj\n".$object."\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 10\n0000000000 65535 f \n";foreach($offsets as$offset)$pdf.=sprintf('%010d 00000 n ',$offset)."\n";$pdf.="trailer\n<< /Size 10 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    file_put_contents($f->dir.'/boundary.pdf',$pdf);
    app\common\service\app\aigc_llm\agent\AgentMediaReader::command(['/usr/bin/ffmpeg','-nostdin','-y','-v','error','-f','lavfi','-i','color=c=red:s=32x32:r=1','-f','lavfi','-i','sine=frequency=440:sample_rate=16000','-t','65','-c:v','libx264','-pix_fmt','yuv420p','-c:a','aac','-threads','1',$f->dir.'/boundary.mp4'],45);
}
