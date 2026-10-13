<?php
declare(strict_types=1);
namespace app\common\service\update;

use RuntimeException;
use Throwable;

/** Filesystem-only maintenance. Never signals processes or changes task/SQL state. */
class RuntimeRetentionService
{
    private string $store;
    public function __construct(private string $root) { $this->root=rtrim($root,'/'); $this->store=$this->root.'/.composer-runtime'; }

    /** Called under the update writer lease before publishing any generation. */
    public function prepare(?string $generation = null): void
    {
        $this->directory($this->store); $this->directory($this->store.'/leases');
        $this->directory($this->store.'/readers');
        // Use the updater's existing runtime owner/group, never a world-writable
        // registry. If a different worker cannot register, cleanup defers safely.
        $readers=$this->store.'/readers';
        if(fileowner($readers)!==fileowner($this->store) && !@chown($readers,fileowner($this->store)))throw new RuntimeException('runtime_reader_owner_unavailable');
        if(filegroup($readers)!==filegroup($this->store) && !@chgrp($readers,filegroup($this->store)))throw new RuntimeException('runtime_reader_group_unavailable');
        if(!@chmod($readers,0775) || !@chmod($this->store.'/leases',0755))throw new RuntimeException('runtime_reader_permissions_unavailable');
        $this->touchLock($this->store.'/retention.lock');
        $ids=$generation===null ? array_map('basename',glob($this->store.'/generations/*')?:[]) : [$generation];
        foreach ($ids as $id) if ($this->generationId($id)) $this->touchLock($this->store.'/leases/'.$id.'.lock');
    }

    public function collect(callable $health): array
    {
        $writer=null; $selection=null; $leases=[];
        try {
            $this->assertPath($this->store.'/update.lock');
            $writer=@fopen($this->store.'/update.lock','r');
            if (!$writer || !flock($writer,LOCK_EX|LOCK_NB)) throw new RuntimeException('update_writer_active');
            if (file_exists($this->store.'/active.json')) throw new RuntimeException('update_recovery_pending');
            $pointer=$this->json($this->store.'/current.json');
            $current=(string)($pointer['generation']??'');
            if (($pointer['schema']??null)!==1 || !$this->generationId($current)) throw new RuntimeException('invalid_current_pointer');
            $keep=[$current]; $backup=null; $matched=false; $committedJournal=[];
            foreach (glob($this->store.'/journals/*.json')?:[] as $path) {
                $journal=$this->json($path);
                if (!in_array($journal['status']??'',['committed','rolled_back'],true) || ($journal['sql_state']??'')==='running') throw new RuntimeException('journal_outcome_unknown');
                if (($journal['generation']??'')!==$current || ($journal['status']??'')!=='committed') continue;
                if ($matched || ($journal['sql_state']??'')!=='done') throw new RuntimeException('current_commit_ambiguous');
                $previous=$journal['previous_pointer']??[];
                if (($previous['schema']??null)!==1 || !$this->generationId((string)($previous['generation']??'')) || !preg_match('/^[a-f0-9]{64}$/D',(string)($journal['backup']??''))) throw new RuntimeException('invalid_rollback_group');
                $keep[]=$previous['generation']; $backup=$journal['backup']; $matched=true; $committedJournal=$journal;
            }
            if (!$matched) throw new RuntimeException('current_commit_unconfirmed');
            foreach ($keep as $id) $this->assertDirectory($this->store.'/generations/'.$id);
            $this->assertDirectory($this->store.'/backups/'.$backup);
            $this->prepare();
            if (($health($this->store.'/generations/'.$current,$writer,$committedJournal)['passed']??false)!==true) throw new RuntimeException('runtime_health_failed');
            $inventory=$this->phpProcesses();
            $this->pruneReaderReceipts($inventory);
            $barrierPath=$this->store.'/retention-readers.json';
            $existing=is_file($barrierPath)?$this->json($barrierPath):[];
            if (($existing['generation']??'')!==$current) $this->writeJson($barrierPath,['schema'=>1,'generation'=>$current,'boot'=>$inventory['boot'],'cutoff'=>$inventory['cutoff']??'0','processes'=>array_diff_key($inventory['processes'],$inventory['registered']??[])]);
            $barrier=$this->json($barrierPath);
            if (($barrier['schema']??null)!==1 || !is_array($barrier['processes']??null) || empty($barrier['boot'])) throw new RuntimeException('invalid_legacy_reader_barrier');
            if ($barrier['boot']===$inventory['boot']) foreach ($barrier['processes'] as $pid=>$start) {
                if (($inventory['processes'][$pid]??null)===$start) throw new RuntimeException('legacy_php_process_active');
            }
            foreach ($inventory['unknown']??[] as $pid=>$start) {
                // A fresh master cannot have pre-protocol opcode caches. Its
                // unregistered children are still unknown until they bootstrap
                // this site; an orphan legacy child must never be overlooked.
                if (isset($inventory['masters'][$pid]) && ($barrier['boot']!==$inventory['boot'] || (float)$start>(float)($barrier['cutoff']??PHP_INT_MAX))) continue;
                throw new RuntimeException('unregistered_php_process_active');
            }

            $candidates=[];
            foreach (glob($this->store.'/generations/*')?:[] as $path) {
                $id=basename($path);
                if (in_array($id,$keep,true)) continue;
                if (!$this->generationId($id) && !preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}\.stage-[a-f0-9]{12}$/D',$id)) throw new RuntimeException('unknown_generation_entry');
                $candidates[]=['path'=>$path,'id'=>$id,'kind'=>'generation'];
            }
            foreach (glob($this->store.'/backups/*')?:[] as $path) {
                $id=basename($path); if ($id===$backup) continue;
                if (!preg_match('/^[a-f0-9]{64}$/D',$id)) throw new RuntimeException('unknown_backup_entry');
                $candidates[]=['path'=>$path,'id'=>$id,'kind'=>'backup'];
            }
            // Prevalidate before taking the selection gate. Readers never wait
            // for a recursive scan or deletion of a large vendor tree.
            foreach ($candidates as $entry) $this->validateTree($entry['path']);
            $this->directory($this->store.'/trash');
            $selection=fopen($this->store.'/retention.lock','r');
            if (!$selection || !flock($selection,LOCK_EX|LOCK_NB)) throw new RuntimeException('runtime_selection_active');
            $busy=false; $removed=[];
            foreach ($candidates as $entry) {
                if ($entry['kind']==='generation' && $this->generationId($entry['id'])) {
                    $lease=fopen($this->store.'/leases/'.$entry['id'].'.lock','r');
                    if (!$lease || !flock($lease,LOCK_EX|LOCK_NB)) { if(is_resource($lease))fclose($lease); $busy=true; continue; }
                    $leases[]=$lease;
                }
                $this->assertDirectory($entry['path']);
                $target=$this->store.'/trash/'.$entry['kind'].'-'.$entry['id'].'-'.bin2hex(random_bytes(6));
                if (!rename($entry['path'],$target)) throw new RuntimeException('quarantine_failed');
                $removed[]=$entry['kind'].':'.$entry['id'];
            }
            fclose($selection); $selection=null;
            $deadline=microtime(true)+2;
            foreach (glob($this->store.'/trash/*')?:[] as $path) {
                if (!preg_match('/^(?:generation-(?:baseline|release)-[a-f0-9]{24,64}(?:\.stage-[a-f0-9]{12})?|backup-[a-f0-9]{64})-[a-f0-9]{12}$/D',basename($path))) throw new RuntimeException('unknown_quarantine_entry');
                $this->validateTree($path);
                if (!$this->removeTree($path,$deadline)) { $busy=true; break; }
            }
            return $this->record(['status'=>$busy?'deferred':'complete','reason'=>$busy?'readers_or_deletion_pending':'retained_current_and_rollback','generation'=>$current,'removed'=>$removed]);
        } catch (Throwable $e) {
            return $this->record(['status'=>'deferred','reason'=>$e->getMessage()]);
        } finally {
            if (is_resource($selection)) fclose($selection);
            foreach ($leases as $lease) fclose($lease);
            if (is_resource($writer)) fclose($writer);
        }
    }

    /** Unconfirmed PHP interpreters at each activation must exit naturally. */
    protected function phpProcesses(): array
    {
        if (PHP_OS_FAMILY!=='Linux' || !is_dir('/proc/'.getmypid())) throw new RuntimeException('process_inventory_unavailable');
        $mounts=@file_get_contents('/proc/mounts');
        if ($mounts===false || preg_match('/\bhidepid=(?!0\b)\S+/',$mounts)) throw new RuntimeException('process_inventory_restricted');
        $boot=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if ($boot==='') throw new RuntimeException('process_boot_identity_unavailable');
        $current=$this->json($this->store.'/current.json')['generation']??'';
        $processes=[]; $registered=[]; $unknown=[]; $masters=[]; $cutoff=null; $paths=glob('/proc/[0-9]*');
        if (!$paths) throw new RuntimeException('process_inventory_unavailable');
        foreach ($paths as $path) {
            $pid=basename($path);
            $stat=@file_get_contents($path.'/stat'); $args=@file_get_contents($path.'/cmdline');
            if ($stat===false || $args===false) { if(!is_dir($path))continue; throw new RuntimeException('process_inventory_unreadable'); }
            $end=strrpos($stat,')'); $fields=$end===false?[]:preg_split('/\s+/',trim(substr($stat,$end+1)));
            if (!isset($fields[19]) || !ctype_digit($fields[19])) throw new RuntimeException('process_identity_unavailable');
            if ((int)$pid===getmypid()) { $cutoff=$fields[19]; continue; }
            $comm=substr($stat,strpos($stat,'(')+1,$end-strpos($stat,'(')-1);
            if (preg_match('/^php(?:[0-9.]|-|$)/i',$comm) || preg_match('#(?:^|\x00|/)php(?:[0-9.]|(?:-fpm))*(?:\x00|\s|$)#i',$args)) {
                $processes[$pid]=$fields[19];
                $receipt=$this->store.'/readers/'.$pid.'-'.$fields[19].'-'.substr(hash('sha256',$boot),0,16).'.json';
                $reader=is_file($receipt)?$this->json($receipt):[];
                if (($reader['schema']??null)===1 && ($reader['boot']??'')===$boot && ($reader['start']??'')===$fields[19] && ($reader['generation']??'')===$current) $registered[$pid]=$fields[19];
                else {$unknown[$pid]=$fields[19];if(str_starts_with($args,'php-fpm: master process'))$masters[$pid]=$fields[19];}
            }
        }
        if($cutoff===null)throw new RuntimeException('process_self_identity_unavailable');
        return ['boot'=>$boot,'cutoff'=>$cutoff,'processes'=>$processes,'registered'=>$registered,'unknown'=>$unknown,'masters'=>$masters];
    }

    public function dispatch(string $php, bool $force=false): void
    {
        try {
            if (!function_exists('proc_open') || !is_executable($php) || !is_file($this->root.'/scripts/runtime-cleanup.php') || !is_file($this->store.'/current.json')) return;
            $state=is_file($this->store.'/retention-state.json')?$this->json($this->store.'/retention-state.json'):[];
            $pointer=$this->json($this->store.'/current.json');
            if (($state['status']??'')==='complete' && ($state['generation']??'')===($pointer['generation']??'')) return;
            if (!$force && time()-(int)($state['checked_at']??0)<60) return;
            $this->touchLock($this->store.'/retention-dispatch.lock');
            $lock=@fopen($this->store.'/retention-dispatch.lock','r');
            if (!$lock || !@flock($lock,LOCK_EX|LOCK_NB)) { if(is_resource($lock))fclose($lock); return; }
            // Only the short shell is waited for. The detached maintenance child
            // inherits this lease; requests/cron never wait for a vendor scan.
            $command='exec '.escapeshellarg($php).' -d opcache.enable_cli=0 '.escapeshellarg($this->root.'/scripts/runtime-cleanup.php').' </dev/null >/dev/null 2>&1 &';
            $pipes=[]; $child=@proc_open(['/bin/sh','-c',$command],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w'],3=>$lock],$pipes,$this->root);
            if (is_resource($child)) proc_close($child);
            fclose($lock);
        } catch (Throwable $e) { /* Maintenance cannot turn a successful update into failure. */ }
    }

    private function generationId(string $id): bool { return (bool)preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/D',$id); }
    private function pruneReaderReceipts(array $inventory): void
    {
        foreach(glob($this->store.'/readers/*.json')?:[] as $path){
            if(!preg_match('/^([0-9]+)-([0-9]+)-[a-f0-9]{16}\.json$/D',basename($path),$match))continue;
            if((int)$match[1]===getmypid())continue;
            $this->assertPath($path);
            if(($inventory['processes'][$match[1]]??null)!==$match[2] || !str_ends_with(basename($path),'-'.substr(hash('sha256',$inventory['boot']),0,16).'.json'))@unlink($path);
        }
    }
    private function assertPath(string $path): void
    {
        if (!str_starts_with($path,$this->store) || !is_dir($this->root) || is_link($this->root)) throw new RuntimeException('unsafe_store_root');
        for ($p=$path; $p!==$this->root; $p=dirname($p)) if(is_link($p))throw new RuntimeException('linked_runtime_path');
    }
    private function assertDirectory(string $path): void { $this->assertPath($path); if(!is_dir($path))throw new RuntimeException('runtime_directory_missing'); }
    private function directory(string $path): void { $this->assertPath($path); if(!is_dir($path) && !@mkdir($path,0755,true))throw new RuntimeException('runtime_directory_unwritable'); }
    private function touchLock(string $path): void { $this->assertPath($path); if(!file_exists($path)){$h=@fopen($path,'x');if(!$h)throw new RuntimeException('runtime_lease_unwritable');fclose($h);}if(!is_file($path) || !@chmod($path,0644))throw new RuntimeException('runtime_lease_permissions_unavailable'); }
    private function json(string $path): array { $this->assertPath($path);if(!is_file($path))throw new RuntimeException('runtime_metadata_invalid');$value=json_decode((string)@file_get_contents($path),true);if(!is_array($value))throw new RuntimeException('runtime_metadata_invalid');return $value; }
    private function writeJson(string $path,array $data): void
    {
        $this->assertPath($path);$temp=$path.'.tmp-'.bin2hex(random_bytes(6));
        try { if(file_put_contents($temp,json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false || !rename($temp,$path))throw new RuntimeException('runtime_metadata_unwritable'); }
        finally { if(is_file($temp))@unlink($temp); }
    }
    private function record(array $result): array { $result['checked_at']=time();try{$this->writeJson($this->store.'/retention-state.json',$result);}catch(Throwable $e){}return $result; }
    private function validateTree(string $path): void
    {
        $this->assertDirectory($path);
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::SELF_FIRST);
        foreach($it as $entry) if($entry->isLink() || (!$entry->isDir() && !$entry->isFile()))throw new RuntimeException('unsafe_runtime_tree');
    }
    private function removeTree(string $path,float $deadline): bool
    {
        $this->assertPath($path); if(microtime(true)>$deadline)return false;
        if(is_dir($path)){foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry)if(!$this->removeTree($entry->getPathname(),$deadline))return false;if(!rmdir($path))throw new RuntimeException('runtime_delete_failed');}
        elseif(!is_file($path) || !unlink($path))throw new RuntimeException('runtime_delete_failed');
        return true;
    }
}
