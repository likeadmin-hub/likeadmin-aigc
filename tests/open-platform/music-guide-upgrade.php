<?php
require dirname(__DIR__,2).'/vendor/autoload.php';(new think\App())->initialize();
function check($ok,$why){if(!$ok)throw new RuntimeException($why);}
$db=config('database.connections.mysql');check($db['hostname']==='127.0.0.1'&&$db['database']==='z_cn','Local fixture only');
$pdo=new PDO('mysql:host=127.0.0.1;port='.($db['hostport']?:3306).';dbname=z_cn;charset=utf8mb4',$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_MULTI_STATEMENTS=>true]);
$root=dirname(__DIR__,2);$table='opf_'.bin2hex(random_bytes(8)).'_music_doc';$sql=file_get_contents($root.'/app/apps/open_platform/migrations/20261012_open_platform_music_guide_v3.sql');
check(preg_match("/AND BINARY original.content=BINARY '((?:[^']|'')*)'\nAND NOT EXISTS/s",$sql,$m)===1,'Previous exact seed not found');$old=str_replace(["''","\\\\"],["'","\\"],$m[1]);
$sql=str_replace('la_open_platform_document',$table,$sql);$pdo->exec('CREATE TABLE `'.$table.'` LIKE `la_open_platform_document`');
try{
    foreach(['exact','custom','future'] as $case){
        $pdo->exec('DELETE FROM `'.$table.'`');$insert=$pdo->prepare('INSERT INTO `'.$table.'` (tenant_id,slug,version,title,category,status,content,contract_code,sort,create_time,update_time) VALUES (?,"open-platform-music-tools",?,"音乐工具接入","开发指南","published",?,"",70,1,1)');
        $insert->execute([0,2,$case==='custom'?'Platform authored guide':$old]);$insert->execute([1,2,'Tenant authored guide']);if($case==='future')$insert->execute([0,4,'Future authored version']);
        for($i=0;$i<2;$i++)$pdo->exec($sql);
        $rows=$pdo->query('SELECT tenant_id,version,status,content FROM `'.$table.'` ORDER BY tenant_id,version')->fetchAll(PDO::FETCH_ASSOC);
        $tenant=array_values(array_filter($rows,fn($r)=>(int)$r['tenant_id']===1));check(count($tenant)===1&&$tenant[0]['content']==='Tenant authored guide'&&$tenant[0]['status']==='published','Tenant guide overwritten');
        $latest=array_values(array_filter($rows,fn($r)=>(int)$r['tenant_id']===0&&(int)$r['version']===3));
        check(count($latest)===($case==='exact'?1:0),'Custom or future guide replaced');
        if($case==='exact')check($latest[0]['status']==='published'&&$latest[0]['content']===file_get_contents($root.'/app/apps/open_platform/docs/music-tools.md')&&$rows[0]['status']==='archived','Exact seed did not upgrade once');
        elseif($case==='custom')check($rows[0]['content']==='Platform authored guide'&&$rows[0]['status']==='published','Platform authored guide changed');
        else check(count($rows)===3&&$rows[0]['status']==='published'&&$rows[1]['content']==='Future authored version','Future version changed');
    }
    echo "PASS exact music seed upgrades once; custom platform, tenant and future versions remain untouched in isolated document table\n";
}finally{$pdo->exec('DROP TABLE `'.$table.'`');}
