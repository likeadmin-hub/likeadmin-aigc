<?php

/** Test-only standalone MySQL. Never reads the configured application's DB or imports its rows. */
final class MarketIsolatedMysqlFixture
{
    public string $root;
    public string $owner;
    public array $database;
    public array $manifest;
    private string $uuid;
    public string $stage = 'namespace_preflight';
    private int $schemaShadowCreated = 0;
    private int $schemaShadowDropped = 0;
    private static array $schemaContext = [];

    public const TABLES = ['ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset',
        'tenant_file','tenant','user','config','tenant_config','app','tenant_app_config','aigc_image_task','aigc_image_result',
        'aigc_image_billing','aigc_image_quota','aigc_short_drama_config','power_market_product','power_market_sku',
        'tenant_power_market_sku_price','power_market_price_rule','aigc_image_channel','aigc_image_channel_spec',
        'aigc_video_channel','aigc_video_channel_spec','aigc_llm_config','aigc_video_task','aigc_video_result',
        'aigc_video_billing','aigc_video_quota','aigc_video_config','aigc_product_promo_video_task',
        'aigc_music_task','aigc_music_result','aigc_music_billing','aigc_music_quota'];

    public function __construct()
    {
        $this->root = rtrim((string)getenv('C03_RUN_ROOT'), '/');
        self::check(PHP_SAPI === 'cli' && $this->root === '/c03/run' && is_dir($this->root) && !is_link($this->root), 'Missing private C03 root');
        $path = $this->root.'/owner.json';
        self::check(is_file($path) && !is_link($path), 'Missing C03 owner manifest');
        $this->manifest = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $this->owner = (string)($this->manifest['owner'] ?? '');
        self::check(preg_match('/^[a-f0-9]{24}$/D', $this->owner) === 1, 'Invalid C03 owner');
        foreach (['mysql_container_id','php_container_id'] as $key) self::check(preg_match('/^[a-f0-9]{64}$/D', (string)($this->manifest[$key] ?? '')) === 1, 'Missing immutable container identity');
        self::check($this->manifest['mysql_container_id'] !== $this->manifest['php_container_id'], 'Runner must be a separate owned container');
        self::check(($this->manifest['volume'] ?? '') === 'c03-mysql-'.$this->owner, 'Invalid C03 volume ownership');
        self::check(($this->manifest['namespace_contract'] ?? '') === 'owned-mysql-network-and-pid', 'Missing private namespace contract');
        self::check(str_contains((string)@file_get_contents('/proc/1/cmdline'),'c03-mysql-supervisor'), 'Not the dedicated private PID namespace');
        self::check(is_file($this->root.'/supervisor-owner') && trim(file_get_contents($this->root.'/supervisor-owner'))===$this->owner,'Supervisor owner differs');
        self::check(!is_file(dirname(__DIR__, 2).'/.env'), 'Do not mount the real environment file');
        self::check(is_file($this->root.'/connection.json') && !is_link($this->root.'/connection.json')
            && (fileperms($this->root.'/connection.json')&0077)===0,'Private connection manifest must have owner-only permissions');
        $secret = json_decode(file_get_contents($this->root.'/connection.json'), true, 16, JSON_THROW_ON_ERROR);
        self::check(($secret['database'] ?? '') === 'x_cn' && ($secret['username'] ?? '') === 'c03_'.substr($this->owner, 0, 12)
            && is_string($secret['password'] ?? null) && strlen($secret['password']) >= 24, 'Invalid private DB identity');
        // The supplied hostname/port are deliberately ignored. The runner shares only the new MySQL namespace.
        $this->database = ['default'=>'mysql', 'connections'=>['mysql'=>[
            'type'=>'mysql','hostname'=>'127.0.0.1','hostport'=>3306,'database'=>'x_cn',
            'username'=>$secret['username'],'password'=>$secret['password'],'charset'=>'utf8mb4','prefix'=>'la_',
            'deploy'=>0,'rw_separate'=>false,'fields_strict'=>true,'break_reconnect'=>false,'trigger_sql'=>false,'fields_cache'=>false,
        ]]];
        $pdo = $this->pdo();
        $this->uuid = (string)$pdo->query('SELECT @@server_uuid')->fetchColumn();
        self::check(preg_match('/^[a-f0-9-]{36}$/iD', $this->uuid) === 1, 'Missing independent MySQL UUID');
        self::check($pdo->query('SELECT DATABASE()')->fetchColumn()==='x_cn' && (int)$pdo->query('SELECT @@port')->fetchColumn()===3306,'Unexpected private DB endpoint');
        self::check((int)$pdo->query('SELECT @@innodb_flush_log_at_trx_commit')->fetchColumn() === 1, 'Require durable transaction commits');
        $uuidPath = $this->root.'/mysql-uuid';
        if (is_file($uuidPath)) self::check(trim(file_get_contents($uuidPath)) === $this->uuid, 'MySQL volume identity changed');
        else self::atomic($uuidPath, $this->uuid);
    }

    public static function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
    public static function diagnostic(Throwable $error, string $stage): array
    {
        $result=['failure_class'=>get_class($error),'failure_fingerprint'=>hash('sha256',$error->getMessage()),
            'failure_stage'=>preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$stage)?$stage:'owned_fixture'];
        if(preg_match('/fields not exists:\[([a-z][a-z0-9_]{0,63})\]/i',$error->getMessage(),$match)
            || preg_match('/Unknown column \'([a-z][a-z0-9_]{0,63})\' in /i',$error->getMessage(),$match))$result['unknown_field']=$match[1];
        if(self::$schemaContext!==[])$result['schema_context']=self::$schemaContext;
        return $result;
    }
    public static function atomic(string $path, string $bytes): void
    {
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        self::check(file_put_contents($tmp, $bytes, LOCK_EX) !== false, 'Cannot write owned marker');
        chmod($tmp, 0600); self::check(rename($tmp, $path), 'Cannot publish owned marker');
    }

    public function pdo(): PDO
    {
        $db = $this->database['connections']['mysql'];
        return new PDO('mysql:host=127.0.0.1;port=3306;dbname=x_cn;charset=utf8mb4', $db['username'], $db['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>2]);
    }

    public function seedEmptySchema(): array
    {
        $this->stage='schema_source';
        $sqlFile = (string)getenv('C03_INSTALL_SQL');
        self::check($sqlFile === '/source/schema/like.sql' && is_file($sqlFile) && !is_link($sqlFile), 'Mount only the source installer SQL');
        $sql = file_get_contents($sqlFile); $pdo = $this->pdo(); $created = []; $createSources=[];
        self::check(hash('sha256',$sql)==='b12bacb3f2039f371ac10f8347f65d897da0069f71fec3bd5b768299ba121660','Review changed installer before deriving isolated DDL');
        $existing=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach($existing as$table)self::check(str_starts_with($table,'la_')&&in_array(substr($table,3),self::TABLES,true),'Unclean or unknown standalone DB tables');
        foreach (self::TABLES as $name) {
            // Only complete CREATE TABLE statements for fixed, allowlisted names.
            $pattern = '~^CREATE TABLE(?: IF NOT EXISTS)? `la_'.preg_quote($name, '~').'`\s*\(.*?^\)\s*ENGINE\s*=\s*InnoDB\b[^;]*;~msi';
            if (preg_match($pattern, $sql, $match) !== 1) continue;
            self::check(!preg_match('/\b(?:DEFINER|TABLESPACE|TRIGGER)\b/i', $match[0]), 'Unexpected schema side effect');
            if(!in_array('la_'.$name,$existing,true))$pdo->exec($match[0]); $created[] = $name; $createSources[$name]=hash('sha256',$match[0]);
        }
        foreach (['tenant','user','tenant_config','tenant_file','ai_app_task','ai_consumption_log','ai_consumption_event','ai_task_job','ai_task_result_asset',
            'aigc_image_task','aigc_image_result','aigc_video_task','aigc_video_result','aigc_music_task','aigc_music_result'] as $name)
            self::check(in_array($name, $created, true), 'Installer is missing a required empty fixture table: '.$name);
        foreach ($created as $name) self::check((int)$pdo->query('SELECT COUNT(*) FROM `la_'.$name.'`')->fetchColumn() === 0, 'Unexpected imported rows');
        self::check(count($created)===35,'Pinned installer base table count differs');
        $before=$this->schemaFacts($pdo,$created);$added=0;$consistent=0;$conditionalSkipped=0;$sources=[];
        foreach(self::supplementalDdl($sql) as $entry){
            $table='la_'.$entry['table'];$clause=$entry['clause'];$name=$entry['name'];$kind=$entry['kind'];$already=false;
            $this->stage=$kind==='column'?'schema_add_column':'schema_add_index';
            self::$schemaContext=['table'=>$entry['table'],'kind'=>$kind,'name'=>$name,'source_line'=>$entry['line'],
                'source_sha256'=>$entry['source_sha256'],'clause_sha256'=>hash('sha256',$clause),
                'base_create_sha256'=>$createSources[$entry['table']],'conditional_source'=>$entry['conditional']];
            self::$schemaContext[$kind]=$name;
            $this->recordSchemaShadowCounts();
            if($kind==='column'){
                $columns=$this->columnFacts($pdo,$table);
                $expected=$this->expectedColumn($pdo,$table,$clause,$name);
                if(isset($columns[$name])){
                    $this->recordColumnComparison($columns[$name],$expected,'existing_column');
                    self::check($columns[$name]===$expected,'Existing isolated column differs from source');$consistent++;$already=true;
                }else{
                    $pdo->exec('ALTER TABLE `'.$table.'` '.$clause);$actual=$this->columnFacts($pdo,$table)[$name]??null;
                    $this->recordColumnComparison($actual,$expected,'after_add_column');
                    self::check($actual===$expected,'Added column differs from source');$added++;
                }
            }else{
                $indexes=$this->indexFacts($pdo,$table);$expected=$entry['index'];
                if(isset($indexes[$name])){self::check($indexes[$name]===$expected,'Existing isolated index differs from source');$consistent++;$already=true;}
                else{$pdo->exec('ALTER TABLE `'.$table.'` '.$clause);self::check(($this->indexFacts($pdo,$table)[$name]??null)===$expected,'Added index differs from source');$added++;}
            }
            if($already&&$entry['conditional'])$conditionalSkipped++;
            $sources[]=['table'=>$entry['table'],'kind'=>$kind,'name'=>$name,'source_line'=>$entry['line'],
                'source_sha256'=>$entry['source_sha256'],'clause_sha256'=>hash('sha256',$clause),'base_create_sha256'=>$createSources[$entry['table']],
                'conditional_source'=>$entry['conditional'],'already_consistent'=>$already];
        }
        $this->stage='schema_verify';
        foreach($created as$name)self::check((int)$pdo->query('SELECT COUNT(*) FROM `la_'.$name.'`')->fetchColumn()===0,'DDL imported unexpected rows');
        self::check(count($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN))===35,'Supplemental DDL changed persistent table set');
        self::check($this->schemaShadowCreated===$this->schemaShadowDropped,'Owned permanent schema shadow cleanup incomplete');
        self::$schemaContext=[];
        return ['schema_sha256'=>hash('sha256', $sql), 'empty_base_tables'=>count($created),'source_database_connections'=>0,'imported_original_rows'=>0,
            'schema_before_sha256'=>hash('sha256',json_encode($before,JSON_THROW_ON_ERROR)),
            'schema_after_sha256'=>hash('sha256',json_encode($this->schemaFacts($pdo,$created),JSON_THROW_ON_ERROR)),
            'source_add_columns'=>22,'source_add_indexes'=>4,'source_add_applied'=>$added,'source_add_already_consistent'=>$consistent,
            'conditional_source_already_consistent'=>$conditionalSkipped,'source_add_provenance'=>$sources,
            'expected_schema_shadow_created'=>$this->schemaShadowCreated,'expected_schema_shadow_dropped'=>$this->schemaShadowDropped,'expected_schema_shadow_remaining'=>0];
    }

    private static function supplementalDdl(string $sql): array
    {
        $allowed=[
            'aigc_image_task'=>['column'=>['channel','quality','reference_images','tenant_cost_points','user_charge_points','provider','model','provider_task_id','provider_params_json','app_task_id'],'index'=>['idx_app_task']],
            'aigc_image_result'=>['column'=>['channel','quality','ratio','tenant_cost_points','user_charge_points','provider_task_id']],
            'aigc_image_billing'=>['column'=>['consumption_id'],'index'=>['idx_consumption']],
            'aigc_video_task'=>['column'=>['reference_assets','duration']],
            'aigc_video_channel_spec'=>['column'=>['upstream_unit_cost']],
            'tenant'=>['column'=>['oem_sales_enabled','oem_flow_enabled']],
            'ai_app_task'=>['index'=>['idx_virtual_refund_user']],
            'ai_consumption_log'=>['index'=>['idx_virtual_refund_billing']],
        ];
        $statements=[];
        preg_match_all('~^ALTER TABLE `la_([a-z][a-z0-9_]+)`\s+([^;]+);~m',$sql,$matches,PREG_SET_ORDER|PREG_OFFSET_CAPTURE);
        foreach($matches as$match){if(isset($allowed[$match[1][0]]))$statements[]=['table'=>$match[1][0],'body'=>trim($match[2][0]),'offset'=>$match[0][1],'source'=>$match[0][0],'conditional'=>false];}
        // Decode exactly four reviewed static literals; never run their SET/IF,
        // PREPARE/EXECUTE wrappers or any installer data/migration program.
        $wrapped=[5058=>'225cc6edf723a4741c93a635cf29b444f9d2b8b97e60547d3f36ae221f27658a',
            5073=>'8d0873028f447154054141494f683d6229901a8ca6643270678671d8b4d6d5d5',
            5267=>'d274cd2c39fbf13e9b360ce61786e0eb362198e6e95829e07366af4c65d2e30e',
            7317=>'2e8aef42db79a6c900626184c8ed2b6ba83f5848e08e236378801a4f66309b1a'];
        $lines=explode("\n",$sql);
        foreach($wrapped as$number=>$sha){
            $line=$lines[$number-1]??'';self::check(hash('sha256',$line."\n")===$sha,'Wrapped ADD source changed');
            self::check(preg_match("~'(ALTER TABLE (?:[^']|'')*)'~",$line,$literal)===1,'Missing reviewed static ADD literal');
            $decoded=str_replace("''","'",$literal[1]);
            self::check(preg_match('~^ALTER TABLE `la_([a-z][a-z0-9_]+)` (ADD COLUMN .+)$~D',$decoded,$parts)===1,'Wrapped literal is not a static ADD COLUMN');
            $statements[]=['table'=>$parts[1],'body'=>$parts[2],'offset'=>strlen(implode("\n",array_slice($lines,0,$number-1)))+1,'source'=>$line."\n",'conditional'=>true];
        }
        usort($statements,static fn($a,$b)=>$a['offset']<=>$b['offset']);$result=[];$seen=[];
        foreach($statements as$statement)foreach(self::splitAddClauses($statement['body'])as$clause){
            $table=$statement['table'];self::check(in_array($table,self::TABLES,true),'Supplemental table is not allowlisted');
            if(preg_match('~^ADD COLUMN `([a-z][a-z0-9_]+)`\s+.+$~sD',$clause,$match)){$kind='column';$name=$match[1];$index=null;}
            elseif(preg_match('~^ADD (UNIQUE )?(?:KEY|INDEX) `([a-z][a-z0-9_]+)`\s*\((`[a-z][a-z0-9_]+`(?:\s*,\s*`[a-z][a-z0-9_]+`)*)\)$~D',$clause,$match)){
                $kind='index';$name=$match[2];preg_match_all('/`([a-z][a-z0-9_]+)`/',$match[3],$columns);
                $index=['columns'=>$columns[1],'non_unique'=>empty($match[1])?1:0,'type'=>'BTREE','sub_parts'=>array_fill(0,count($columns[1]),null)];
            }else throw new RuntimeException('Only finite ADD COLUMN/INDEX clauses are permitted');
            self::check(in_array($name,$allowed[$table][$kind]??[],true),'Supplemental identifier is not allowlisted');
            self::check(!preg_match('/\b(?:DEFINER|TABLESPACE|TRIGGER|GENERATED|REFERENCES|SELECT|INSERT|UPDATE|DELETE|DROP|MODIFY|CHANGE|RENAME|PREPARE|EXECUTE)\b|;|--|\/\*/i',$clause),'Unsafe supplemental ADD clause');
            $key=$table.'.'.$kind.'.'.$name;self::check(!isset($seen[$key]),'Duplicate source ADD identifier');$seen[$key]=true;
            $result[]=['table'=>$table,'kind'=>$kind,'name'=>$name,'clause'=>$clause,'index'=>$index,'conditional'=>$statement['conditional'],
                'line'=>substr_count(substr($sql,0,$statement['offset']),"\n")+1,'source_sha256'=>hash('sha256',$statement['source'])];
        }
        $expected=0;foreach($allowed as$table=>$kinds)foreach($kinds as$kind=>$names)foreach($names as$name){$expected++;self::check(isset($seen[$table.'.'.$kind.'.'.$name]),'Missing finite source ADD identifier');}
        self::check($expected===26&&count($result)===26,'Source ADD manifest count differs');return$result;
    }

    private static function splitAddClauses(string $body): array
    {
        $result=[];$start=0;$depth=0;$quote='';$length=strlen($body);
        for($i=0;$i<$length;$i++){
            $char=$body[$i];
            if($quote!==''){if($char===$quote){if(($body[$i+1]??'')===$quote)$i++;else$quote='';}continue;}
            if($char==="'"||$char==='`'){$quote=$char;continue;}
            if($char==='(')$depth++;elseif($char===')')$depth--;elseif($char===','&&$depth===0){$result[]=trim(substr($body,$start,$i-$start));$start=$i+1;}
            self::check($depth>=0,'Unbalanced source ADD definition');
        }
        self::check($quote===''&&$depth===0,'Unclosed source ADD definition');$result[]=trim(substr($body,$start));return$result;
    }

    private function columnFacts(PDO $pdo,string $table): array
    {
        $result=[];foreach($pdo->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row){
            $result[$row['Field']]=array_intersect_key($row,array_flip(['Field','Type','Collation','Null','Default','Extra','Comment']));
        }return$result;
    }

    private function indexFacts(PDO $pdo,string $table): array
    {
        $groups=[];foreach($pdo->query('SHOW INDEX FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC)as$row)$groups[$row['Key_name']][(int)$row['Seq_in_index']]=$row;
        $result=[];foreach($groups as$name=>$rows){ksort($rows);$rows=array_values($rows);$result[$name]=['columns'=>array_column($rows,'Column_name'),
            'non_unique'=>(int)$rows[0]['Non_unique'],'type'=>$rows[0]['Index_type'],'sub_parts'=>array_column($rows,'Sub_part')];}ksort($result);return$result;
    }

    private function schemaFacts(PDO $pdo,array $tables): array
    {
        $result=[];foreach($tables as$table)$result[$table]=['columns'=>$this->columnFacts($pdo,'la_'.$table),'indexes'=>$this->indexFacts($pdo,'la_'.$table)];return$result;
    }

    private function recordSchemaShadowCounts(): void
    {
        self::$schemaContext['expected_schema_shadow_created']=$this->schemaShadowCreated;
        self::$schemaContext['expected_schema_shadow_dropped']=$this->schemaShadowDropped;
        self::$schemaContext['expected_schema_shadow_remaining']=$this->schemaShadowCreated-$this->schemaShadowDropped;
    }

    private static function schemaValueDiagnostic(bool $present,mixed $value,string $key): array
    {
        $facts=['present'=>$present,'debug_type'=>$present?get_debug_type($value):'missing',
            'typed_sha256'=>hash('sha256',serialize(['present'=>$present,'type'=>$present?get_debug_type($value):'missing','value'=>$value]))];
        // These describe source DDL only. Defaults and comments always remain
        // typed digests, even when their contents happen to be an empty value.
        if($present&&in_array($key,['Type','Null','Collation','Extra'],true)
            && ($value===null||is_string($value)))$facts['value']=$value;
        return$facts;
    }

    private function recordColumnComparison(?array $actual,array $expected,string $phase): void
    {
        $diff=[];$keys=array_values(array_unique(array_merge(array_keys($expected),array_keys($actual??[]))));
        foreach($keys as$key){
            $hasExpected=array_key_exists($key,$expected);$hasActual=is_array($actual)&&array_key_exists($key,$actual);
            if($hasExpected===$hasActual && (!$hasExpected||$expected[$key]===$actual[$key]))continue;
            $diff[$key]=['expected'=>self::schemaValueDiagnostic($hasExpected,$expected[$key]??null,$key),
                'actual'=>self::schemaValueDiagnostic($hasActual,$actual[$key]??null,$key)];
        }
        self::$schemaContext+=['comparison_phase'=>$phase,'strict_equal'=>$actual===$expected,
            'expected_debug_type'=>get_debug_type($expected),'actual_debug_type'=>get_debug_type($actual),
            'expected_typed_sha256'=>hash('sha256',serialize($expected)),'actual_typed_sha256'=>hash('sha256',serialize($actual)),
            'expected_key_order'=>array_keys($expected),'actual_key_order'=>array_keys($actual??[]),
            'key_order_equal'=>array_keys($expected)===array_keys($actual??[]),'diff_keys'=>array_keys($diff),'differences'=>$diff];
        $this->recordSchemaShadowCounts();
    }

    private function expectedColumn(PDO $pdo,string $table,string $clause,string $name): array
    {
        // Parse permanent-table metadata using a permanent owned shadow too.
        // MySQL's SHOW metadata for TEMPORARY tables has a different Extra
        // representation; comparing it would reject equal permanent DDL.
        $shadow='c03_schema_'.$this->owner.'_'.bin2hex(random_bytes(4));
        self::check(preg_match('/^c03_schema_'.preg_quote($this->owner,'/').'_[a-f0-9]{8}$/D',$shadow)===1,'Invalid owned schema shadow identity');
        self::check(!in_array($shadow,$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true),'Owned schema shadow already exists');
        $query=$pdo->prepare('SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$query->execute([$table]);$collation=$query->fetchColumn();
        self::check(is_string($collation)&&preg_match('/^utf8mb4_[a-z0-9_]+$/D',$collation),'Unexpected private table collation');
        $definition=preg_replace('/\s+(?:AFTER `[a-z][a-z0-9_]+`|FIRST)\s*$/D','',substr($clause,strlen('ADD COLUMN ')));
        $pdo->exec('CREATE TABLE `'.$shadow.'` ('.$definition.') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE='.$collation);$this->schemaShadowCreated++;
        self::$schemaContext['expected_schema_shadow_sha256']=hash('sha256',$shadow);
        $this->recordSchemaShadowCounts();
        try{$expected=$this->columnFacts($pdo,$shadow)[$name]??null;self::check(is_array($expected),'Cannot parse the reviewed source column');return$expected;}
        finally{
            $pdo->exec('DROP TABLE `'.$shadow.'`');
            self::check(!in_array($shadow,$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN),true),'Owned permanent schema shadow remains');
            $this->schemaShadowDropped++;
            $this->recordSchemaShadowCounts();
        }
    }

    public function activateWorker(string $prefix, string $dir, bool $barrier = false): void
    {
        MarketWorkerFixture::activate($this->database, $prefix, $dir);
        if ($barrier) MarketDbCommitBarrier::install($this, $prefix, $dir);
        putenv('AI_TASK_WORKER_PID_FILE='.$dir.'/owned-worker.pid');
    }

    public function reconnect(MarketWorkerFixture $fixture): void
    {
        $pdo = $this->pdo();
        self::check($pdo->query('SELECT @@server_uuid')->fetchColumn() === $this->uuid, 'Restored DB is not the same owned volume');
        try { think\facade\Db::connect()->close(); } catch (Throwable) {}
        $property = new ReflectionProperty(MarketWorkerFixture::class, 'pdo');
        $property->setAccessible(true); $property->setValue($fixture, $pdo);
        $this->activateWorker($fixture->prefix, $fixture->dir);
    }

    public function interruptAndRestart(MarketWorkerFixture $fixture): array
    {
        $pidPath = $this->root.'/mysqld.pid';
        self::check(is_file($pidPath) && !is_link($pidPath), 'Missing owned mysqld PID');
        $pid = (int)trim(file_get_contents($pidPath));
        self::check($pid > 1 && trim((string)@file_get_contents('/proc/'.$pid.'/comm')) === 'mysqld', 'Fault target must be the private mysqld child');
        self::check(str_contains((string)@file_get_contents('/proc/1/cmdline'), 'c03-mysql-supervisor'), 'Not the dedicated PID namespace');
        $before = $this->pdo(); self::check($before->query('SELECT @@server_uuid')->fetchColumn() === $this->uuid, 'Wrong DB before interruption');
        $started = microtime(true);
        self::check(posix_kill($pid, SIGKILL), 'Cannot interrupt the owned mysqld');
        MarketWorkerFixture::wait(fn()=>!is_dir('/proc/'.$pid), 'Owned mysqld did not exit', 10);
        $unavailable = false;
        try { $this->pdo()->query('SELECT 1'); } catch (Throwable) { $unavailable = true; }
        self::check($unavailable, 'The intended database was not interrupted');
        $fixture->stop('worker', true);
        self::atomic($this->root.'/restart.request', $this->owner);
        MarketWorkerFixture::wait(function () {
            try { return $this->pdo()->query('SELECT @@server_uuid')->fetchColumn() === $this->uuid; }
            catch (Throwable) { return false; }
        }, 'The same owned MySQL volume did not recover', 60);
        $this->reconnect($fixture);
        return ['mysqld_process_sigkill'=>true,'database_unavailable_observed'=>true,'same_server_uuid_after_restart'=>true,
            'elapsed_ms'=>(int)((microtime(true)-$started)*1000),'container_restarted'=>false,'host_power_loss_tested'=>false];
    }

    public function fixture(string $kind, ?string $source = null): array
    {
        $this->stage='fixture_create';
        $f = new MarketWorkerFixture($this->database, self::TABLES);
        foreach (['enable'=>1,'default'=>'local'] as $key=>$value) think\facade\Db::name('tenant_config')->insert([
            'tenant_id'=>$f->tenant,'type'=>'storage','name'=>$key,'value'=>json_encode($value, JSON_THROW_ON_ERROR)]);
        $native = $kind === 'audio' ? 'music' : $kind;
        $table = 'aigc_'.$native.'_task'; $results = 'aigc_'.$native.'_result';
        ['ext'=>$ext,'mime'=>$mime,'bytes'=>$bytes]=self::media($kind);
        $uri = $source ?? 'data:'.$mime.';base64,'.base64_encode($bytes);
        $key = ['image'=>'images','video'=>'videos','audio'=>'items'][$kind]; $uriKey=$native === 'music' ? 'audio_uri' : $kind.'_uri';
        $app = 'aigc_'.$native;
        $task = app\common\model\ai\AiAppTask::create(['task_no'=>$f->prefix.'task','tenant_id'=>$f->tenant,'user_id'=>$f->user,'app_code'=>$app,
            'action_code'=>'generate','business_table'=>$table,'business_id'=>0,'status'=>'success','progress'=>100,'idempotency_key'=>$f->prefix.'task','create_time'=>time(),'update_time'=>time(),'finish_time'=>0]);
        $c = app\common\model\ai\AiConsumptionLog::create(['consume_no'=>$f->prefix.'consume','app_task_id'=>$task['id'],'tenant_id'=>$f->tenant,'user_id'=>$f->user,
            'app_code'=>$app,'action_code'=>'generate','resource_type'=>'model','provider'=>'power_market','protocol'=>$kind.'_generate','quantity'=>1,
            'price_snapshot'=>['platform_price'=>1.25,'tenant_price'=>2.75,'marker'=>'owned-c03-original'],'usage_snapshot'=>['total_tokens'=>37],
            'request_summary'=>['intent'=>$f->prefix.'original'],'response_summary'=>[$key=>[[$uriKey=>$uri]+($kind==='video'?['width'=>32,'height'=>32]:['title'=>'Synthetic'])]],
            'run_status'=>'success','billing_status'=>'settled','reserved_tenant_cost'=>3.5,'reserved_user_price'=>4.25,'actual_tenant_cost'=>1.25,'actual_user_price'=>2.75,
            'create_time'=>time(),'update_time'=>time(),'finish_time'=>time()]);
        $business=think\facade\Db::name($table)->insertGetId(['tenant_id'=>$f->tenant,'user_id'=>$f->user,'app_task_id'=>$task['id'],'provider'=>$kind==='audio'?'market_music_generation':'power_market',
            'channel'=>'offline-fixture','quality'=>'fixture','ratio'=>'1:1','quantity'=>1,'prompt'=>'Synthetic result, no generation','status'=>'running','delete_time'=>0,
            'create_time'=>time(),'update_time'=>time()]+($kind==='image'?[]:($kind==='audio'?['title'=>'Synthetic']:['model_json'=>json_encode(['resource_type'=>'model','duration'=>1])])));
        if ($kind !== 'image') think\facade\Db::name($table)->where('id',$business)->update(['consumption_id'=>$c['id']]);
        $task->save(['business_id'=>$business]);
        $assets=app\common\service\ai\AiTaskResultAssetService::recordConsumptionAssets((int)$c['id'],true);
        self::check(count($assets)===1, 'Missing isolated transfer asset');
        $asset=(int)$assets[0]['id']; $job=think\facade\Db::name('ai_task_job')->where('result_asset_id',$asset)->find();
        self::check($job && $job['job_type']==='transfer_result', 'No original transfer job');
        return compact('f','kind','table','results','ext','mime','bytes','key','uriKey','app','task','c','business','asset','job','uri');
    }

    public static function media(string $kind): array
    {
        self::check(in_array($kind,['image','video','audio'],true),'Invalid synthetic media kind');
        $ext=['image'=>'png','video'=>'mp4','audio'=>'wav'][$kind];$mime=['image'=>'image/png','video'=>'video/mp4','audio'=>'audio/wav'][$kind];
        if($kind==='image')$bytes=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=');
        elseif($kind==='audio'){$pcm=str_repeat("\0",16000);$bytes='RIFF'.pack('V',36+strlen($pcm)).'WAVEfmt '.pack('VvvVVvv',16,1,1,8000,16000,2,16).'data'.pack('V',strlen($pcm)).$pcm;}
        else{$file='/c03/media/synthetic.mp4';self::check(is_file($file)&&!is_link($file),'Supply the owned synthetic MP4');$bytes=file_get_contents($file);}
        return compact('ext','mime','bytes');
    }

    public static function verifyStored(array $s): array
    {
        $f=$s['f']; $asset=app\common\model\ai\AiTaskResultAsset::find($s['asset'])->toArray();
        $result=think\facade\Db::name($s['results'])->where('task_id',$s['business'])->find();
        self::check($asset['transfer_status']==='stored' && $result && $result[$s['uriKey']]===$asset['local_uri'], 'Managed result not projected');
        self::check((int)$asset['app_task_id']===(int)$s['task']['id'] && (int)$asset['consumption_id']===(int)$s['c']['id'],'Original asset task/consumption link changed');
        $path=$f->dir.'/public/'.$asset['local_uri'];
        self::check(is_file($path) && !is_link($path) && hash_file('sha256',$path)===hash('sha256',$s['bytes']), 'Managed bytes differ');
        $receipt=$asset['storage_meta']; self::check(($receipt['mime_type']??'')===$s['mime'] && ($receipt['checksum']??'')===hash('sha256',$s['bytes']), 'Receipt facts differ');
        $file=think\facade\Db::name('tenant_file')->where('id',(int)($receipt['file_id']??0))->find();
        self::check($file && $file['uri']===$asset['local_uri'] && (int)$file['tenant_id']===$f->tenant && (int)$file['source_id']===$f->user
            && (int)$asset['tenant_id']===$f->tenant && (int)$asset['user_id']===$f->user
            && (int)$result['tenant_id']===$f->tenant && (int)$result['user_id']===$f->user,'Managed ownership differs');
        foreach(['storage_scope','storage_engine','storage_domain']as$field)
            self::check($file[$field]===$asset[$field] && $result[$field]===$asset[$field],'Storage receipt metadata differs');
        self::check((glob(dirname($path).'/*.part')?:[])===[] && (glob(dirname($path).'/.asset-*.part')?:[])===[],'Unfinished managed file remains');
        foreach (['tenant_file','ai_task_result_asset',$s['results'],'ai_app_task','ai_consumption_log'] as $table)
            self::check((int)think\facade\Db::name($table)->count()===1, 'Duplicate isolated result or task');
        self::check((float)think\facade\Db::name('tenant')->where('id',$f->tenant)->value('point_balance')===100.
            && (float)think\facade\Db::name('user')->where('id',$f->user)->value('user_money')===100., 'Fixture wallet changed');
        $after=app\common\model\ai\AiConsumptionLog::find($s['c']['id'])->toArray();
        self::check(array_diff_key($s['frozen'],['response_summary'=>1,'update_time'=>1])===array_diff_key($after,['response_summary'=>1,'update_time'=>1]), 'Frozen consumption changed');
        return ['managed_sha256'=>hash_file('sha256',$path),'managed_bytes'=>filesize($path),'one_asset_file_business_task_consumption'=>true,'wallets_unchanged'=>true,'frozen_billing_request_identity_unchanged'=>true];
    }

    public static function supervisor(): string
    {
        return <<<'SH'
#!/bin/sh
# PID 1 command must contain c03-mysql-supervisor. Only the private run root is writable.
root=/control
test -s "$root/supervisor-owner" || exit 91
while :; do
    rm -f "$root/restart.request"
    docker-entrypoint.sh mysqld --innodb-flush-log-at-trx-commit=1 --sync-binlog=1 &
    c03_mysql_pid=$!
    printf '%s' "$c03_mysql_pid" > "$root/mysqld.pid"
    wait "$c03_mysql_pid"
    printf 'exited' > "$root/mysqld.exited"
    while [ ! -f "$root/restart.request" ]; do sleep 0.1; done
    test "$(cat "$root/restart.request")" = "$(cat "$root/supervisor-owner")" || exit 92
done
SH;
    }
}

if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__ && ($argv[1]??'')==='--emit-supervisor') echo MarketIsolatedMysqlFixture::supervisor();
