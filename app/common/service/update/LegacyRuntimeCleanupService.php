<?php
declare(strict_types=1);
namespace app\common\service\update;
use RuntimeException;
use Throwable;

/** One-time retirement of legacy snapshots; only this site's actual kernel leases matter. */
class LegacyRuntimeCleanupService
{
    public function __construct(private string $root) { $this->root=rtrim($root,'/'); }
    public function collect(): array
    {
        $handles=[];$retired=null;
        try {
            if(!DirectSiteUpdateService::enabled($this->root))throw new RuntimeException('canonical_migration_not_committed');
            $store=$this->root.'/.composer-runtime';
            if(!is_dir($store))return $this->removeRetired();
            if(is_link($store)||is_file($this->root.'/runtime/direct-update-active.json')||is_file($store.'/active.json'))throw new RuntimeException('update_or_recovery_active');
            foreach([$this->root.'/runtime/site-update.lock',$store.'/update.lock',$store.'/retention.lock'] as $path){
                if(!is_file($path)||is_link($path))throw new RuntimeException('legacy_lease_protocol_unavailable');
                $handle=fopen($path,'r');$handles[]=$handle;
                if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('site_reader_or_update_active');
            }
            foreach(glob($store.'/journals/*.json')?:[] as $file){
                $journal=json_decode((string)file_get_contents($file),true);
                if(!is_array($journal)||!in_array($journal['status']??'',['committed','rolled_back'],true)||($journal['sql_state']??'')==='running')throw new RuntimeException('legacy_journal_unconfirmed');
            }
            foreach(glob($store.'/generations/*')?:[] as $generation){
                $id=basename($generation);
                if(!preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}(?:\.stage-[a-f0-9]{12})?$/D',$id))throw new RuntimeException('unknown_legacy_generation');
                if(str_contains($id,'.stage-'))continue;
                $path=$store.'/leases/'.$id.'.lock';
                if(!is_file($path)||is_link($path))throw new RuntimeException('legacy_reader_protection_missing');
                $handle=fopen($path,'r');$handles[]=$handle;
                if(!$handle||!flock($handle,LOCK_EX|LOCK_NB))throw new RuntimeException('legacy_reader_active');
            }
            $allowed=['generations','backups','journals','leases','readers','trash','current.json','update.lock','retention.lock','retention-state.json','retention-readers.json','retention-dispatch.lock'];
            foreach(new \FilesystemIterator($store,\FilesystemIterator::SKIP_DOTS) as $entry)if(!in_array($entry->getFilename(),$allowed,true)||$entry->isLink())throw new RuntimeException('unknown_legacy_store_entry');
            foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($store,\FilesystemIterator::SKIP_DOTS)) as $entry)if($entry->isLink())throw new RuntimeException('linked_legacy_store');
            $directory=$this->root.'/runtime/update_workspace';if(!is_dir($directory)&&!mkdir($directory,0755,true))throw new RuntimeException('cleanup_workspace_unwritable');
            $retired=$directory.'/legacy-retired-'.bin2hex(random_bytes(8));
            if(!rename($store,$retired))throw new RuntimeException('legacy_retirement_failed');
        } catch(Throwable $e) { return ['status'=>'deferred','reason'=>$e->getMessage()]; }
        finally { foreach($handles as $handle)if(is_resource($handle))fclose($handle); }
        try {
            return $this->removeRetired();
        }catch(Throwable $e){return ['status'=>'deferred','reason'=>'retired_storage_removal_pending'];}
    }
    private function removeRetired(): array
    {
        try {
            foreach(glob($this->root.'/runtime/update_workspace/legacy-retired-*')?:[] as $path) {
                if(!preg_match('/^legacy-retired-[a-f0-9]{16}$/D',basename($path)))throw new RuntimeException('unknown_retired_directory');
                $this->remove($path);
            }
            return ['status'=>'complete','reason'=>'canonical_site_only'];
        } catch(Throwable $e) { return ['status'=>'deferred','reason'=>$e->getMessage()]; }
    }
    private function remove(string $path):void
    {
        if(is_link($path))throw new RuntimeException('cleanup_link_rejected');
        if(is_dir($path)){foreach(new \FilesystemIterator($path,\FilesystemIterator::SKIP_DOTS) as $entry)$this->remove($entry->getPathname());if(!rmdir($path))throw new RuntimeException('cleanup_pending');}
        elseif(is_file($path)&&!unlink($path))throw new RuntimeException('cleanup_pending');
    }
}
