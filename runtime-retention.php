<?php
declare(strict_types=1);

/** Loaded before Composer: a request/worker holds its generation until exit. */
final class AigcRuntimeGenerationLease
{
    private $selection;
    private array $leases = [];
    private bool $pinned = false;
    private bool $failed = false;
    private string $generation = '';

    private function __construct(private string $store, $selection)
    {
        $this->selection = $selection;
    }

    public static function begin(string $root): ?self
    {
        $store = $root.'/.composer-runtime';
        // Ordinary installs remain read-only. The updater creates the protocol
        // files before retention is enabled; readers only open existing locks.
        if (!is_file($store.'/current.json') || !is_file($store.'/retention.lock')) return null;
        foreach ([$store,$store.'/retention.lock',$store.'/leases'] as $path) {
            if (is_link($path)) return null;
        }
        $handle = @fopen($store.'/retention.lock','r');
        if (!$handle || !@flock($handle,LOCK_SH)) { if (is_resource($handle)) fclose($handle); return null; }
        return new self($store,$handle);
    }

    public function pin(string $id): void
    {
        if (isset($this->leases[$id])) { $this->generation=$id; return; }
        if (!preg_match('/^(?:baseline|release)-[a-f0-9]{24,64}$/D',$id)) { $this->failed=true; return; }
        $path = $this->store.'/leases/'.$id.'.lock';
        if (is_link($path)) { $this->failed=true; return; }
        $handle = @fopen($path,'r');
        if (!$handle || !@flock($handle,LOCK_SH)) { if (is_resource($handle)) fclose($handle); $this->failed=true; return; }
        $this->leases[$id] = $handle;
        $this->pinned = true;
        $this->generation = $id;
    }

    public function selected(): void
    {
        // If pinning failed, retain the selection lock for the entire request:
        // cleanup must defer, while the application can continue serving.
        if ($this->pinned && !$this->failed && is_resource($this->selection)) {
            try { $this->registerProcess(); } catch (\Throwable $e) { /* Unregistered readers defer cleanup. */ }
            fclose($this->selection); $this->selection = null;
        }
    }

    public function holds(string $id): bool { return !$this->failed && isset($this->leases[$id]); }

    private function registerProcess(): void
    {
        // Persistent identity lets retention distinguish idle/new FPM workers
        // from uninstrumented children forked by a legacy FPM master.
        if (PHP_OS_FAMILY!=='Linux') return;
        $stat=(string)@file_get_contents('/proc/self/stat');$end=strrpos($stat,')');
        $fields=$end===false?[]:preg_split('/\s+/',trim(substr($stat,$end+1)));
        $boot=trim((string)@file_get_contents('/proc/sys/kernel/random/boot_id'));
        if (!isset($fields[19]) || !ctype_digit($fields[19]) || $boot==='') return;
        $directory=$this->store.'/readers';
        if (!is_dir($directory) || is_link($directory)) return;
        $path=$directory.'/'.getmypid().'-'.$fields[19].'-'.substr(hash('sha256',$boot),0,16).'.json';
        if (is_link($path)) return;
        $receipt=['schema'=>1,'boot'=>$boot,'start'=>$fields[19],'generation'=>$this->generation];
        if (is_file($path) && json_decode((string)@file_get_contents($path),true)===$receipt) return;
        $temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
        if (@file_put_contents($temporary,json_encode($receipt,JSON_UNESCAPED_SLASHES),LOCK_EX)!==false) @rename($temporary,$path);
        if(is_file($temporary))@unlink($temporary);
    }
}
