<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Two-device three-way synchronization: local snapshot, last acknowledged
 * snapshots, remote ETag. Never overwrite divergent state automatically.
 */
final class SettingsSyncService
{
    private const STATE='settings-sync-state.json';
    private const KEY='settings-sync-key.json';

    public function __construct(
        private readonly LocalStore $store,
        private readonly NextcloudSettingsDav $dav,
    ) {}

    public function status(): array
    {
        $state=$this->store->read(self::STATE,['enabled'=>false]);
        return [
            'enabled'=>$state['enabled']??false,
            'state'=>$state['state']??'disabled',
            'synced_at'=>$state['synced_at']??null,
            'include_credentials'=>$state['include_credentials']??false,
        ];
    }

    private function credentials(): array
    {
        $creds=$this->store->credentials();
        NextcloudSettingsDav::scope($creds);
        return $creds;
    }

    private function saveKey(string $key,string $salt): void
    {
        $this->store->write(self::KEY,[
            'wrapped'=>Crypt::encryptString(base64_encode($key)),
            'salt'=>base64_encode($salt),
        ]);
    }

    private function key(): array
    {
        $raw=$this->store->read(self::KEY);
        if (!is_string($raw['wrapped']??null)||!is_string($raw['salt']??null)) {
            throw new RuntimeException('Ative a sincronização com sua senha de recuperação.');
        }
        try {
            $key=base64_decode(Crypt::decryptString($raw['wrapped']),true);
            $salt=base64_decode($raw['salt'],true);
        } catch (Throwable) {
            throw new RuntimeException('A chave local do backup não está disponível.');
        }
        if (!is_string($key)||strlen($key)!==32 ||
            !is_string($salt)||strlen($salt)!==SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            throw new RuntimeException('Chave local inválida; reative o backup.');
        }
        return [$key,$salt];
    }

    private function withLock(callable $callback): mixed
    {
        $path=$this->store->root().'/settings-sync.lock';
        if (is_link($path))throw new RuntimeException('Arquivo de lock inseguro.');
        $handle=fopen($path,'c');
        if ($handle===false)throw new RuntimeException('Não foi possível abrir lock do backup.');
        chmod($path,0600);
        if (!flock($handle,LOCK_EX|LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Outra operação de sincronização está em andamento.');
        }
        try {return $callback();}
        finally {flock($handle,LOCK_UN);fclose($handle);}
    }

    private static function saved(array $state,string $label,
                                  ?string $localDigest=null,?string $remoteDigest=null,
                                  ?string $etag=null): array
    {
        $state['state']=$label;
        if ($localDigest!==null)$state['last_local_digest']=$localDigest;
        if ($remoteDigest!==null)$state['last_remote_digest']=$remoteDigest;
        if ($etag!==null)$state['remote_etag']=$etag;
        if ($label==='synced')$state['synced_at']=gmdate('c');
        return $state;
    }

    /**
     * First device creates encrypted backup. Subsequent device decrypts it,
     * and restores only when no existing local configuration would be replaced.
     */
    public function enable(string $passphrase,bool $includeSecrets): string
    {
        return $this->withLock(function() use ($passphrase,$includeSecrets): string {
            $creds=$this->credentials();
            $scope=NextcloudSettingsDav::scope($creds);
            $state=$this->store->read(self::STATE);
            if ($state['enabled']??false) {
                throw new RuntimeException('Desative primeiro para trocar a chave ou as opções do backup.');
            }
            $remote=$this->dav->read($creds);
            $local=SettingsSnapshot::fromStore($this->store,$includeSecrets);
            if ($remote===null) {
                $salt=SettingsVault::generateSalt();
                $key=SettingsVault::derive($passphrase,$salt);
                $encrypted=SettingsVault::seal($local,$key,$salt,$scope);
                $created=$this->dav->write($creds,$encrypted,null);
                $etag=$created['etag'];
                $digest=SettingsSnapshot::digest($local);
                $label='created';
                $state=self::saved([
                    'enabled'=>true,'include_credentials'=>$includeSecrets,
                    'scope'=>$scope,
                ],'synced',$digest,$digest,$etag);
            } else {
                [$raw,$key,$salt]=SettingsVault::unlock($remote['raw'],$passphrase,$scope);
                $snapshot=SettingsSnapshot::validated($raw,$includeSecrets);
                $digest=SettingsSnapshot::digest($snapshot);
                $state=[
                    'enabled'=>true,'include_credentials'=>$includeSecrets,
                    'scope'=>$scope,'remote_etag'=>$remote['etag'],
                ];
                if (SettingsSnapshot::hasLocalConfiguration($this->store)) {
                    $label='needs_resolution';
                    $state=self::saved($state,$label);
                } else {
                    SettingsSnapshot::restore($this->store,$snapshot);
                    $localDigest=SettingsSnapshot::digest(
                        SettingsSnapshot::fromStore($this->store,$includeSecrets)
                    );
                    $label='restored';
                    $state=self::saved($state,'synced',$localDigest,$digest,$remote['etag']);
                }
            }
            $this->saveKey($key,$salt);
            sodium_memzero($key);
            $this->store->write(self::STATE,$state);
            return $label;
        });
    }

    /** Use explicitly when local/remote started from different settings. */
    public function resolve(string $decision): string
    {
        if (!in_array($decision,['remote','local'],true)) {
            throw new RuntimeException('Escolha inválida de resolução.');
        }
        return $this->withLock(function() use ($decision): string {
            [$state,$creds,$scope,$key,$salt]=$this->context();
            try {
                $remote=$this->dav->read($creds);
                if ($remote===null)throw new RuntimeException('Backup remoto ausente; operação cancelada.');
                $snapshot=SettingsSnapshot::validated(
                    SettingsVault::open($remote['raw'],$key,$scope),
                    $state['include_credentials']
                );
                // Even an explicit resolve must not silently use a newer
                // remote version that arrived after the conflict was displayed.
                if ($remote['etag']!==($state['remote_etag']??null)) {
                    $this->store->write(self::STATE,
                        self::saved($state,'conflict',etag:$remote['etag']));
                    throw new SettingsSyncConflict('Backup remoto mudou; revise o conflito novamente.');
                }
                if ($decision==='local') {
                    $local=SettingsSnapshot::fromStore($this->store,$state['include_credentials']);
                    $written=$this->dav->write(
                        $creds,SettingsVault::seal($local,$key,$salt,$scope),$remote['etag']
                    );
                    $digest=SettingsSnapshot::digest($local);
                    $state=self::saved($state,'synced',$digest,$digest,$written['etag']);
                } else {
                    SettingsSnapshot::restore($this->store,$snapshot);
                    $localDigest=SettingsSnapshot::digest(
                        SettingsSnapshot::fromStore($this->store,$state['include_credentials'])
                    );
                    $state=self::saved($state,'synced',$localDigest,
                        SettingsSnapshot::digest($snapshot),$remote['etag']);
                }
                $this->store->write(self::STATE,$state);
                return $decision==='local'?'uploaded':'restored';
            } finally {sodium_memzero($key);}
        });
    }

    private function context(): array
    {
        $state=$this->store->read(self::STATE);
        if (!($state['enabled']??false) || !is_bool($state['include_credentials']??null)) {
            throw new RuntimeException('Sincronização desativada.');
        }
        $creds=$this->credentials();
        $scope=NextcloudSettingsDav::scope($creds);
        if (!is_string($state['scope']??null) || !hash_equals($state['scope'],$scope)) {
            throw new RuntimeException('A conexão Nextcloud mudou; desative e reconfigure o backup.');
        }
        [$key,$salt]=$this->key();
        return [$state,$creds,$scope,$key,$salt];
    }

    public function synchronize(): string
    {
        return $this->withLock(function(): string {
            [$state,$creds,$scope,$key,$salt]=$this->context();
            try {
                if (($state['state']??null)==='needs_resolution' ||
                    ($state['state']??null)==='conflict')return 'needs_resolution';
                $remote=$this->dav->read($creds);
                if ($remote===null)throw new RuntimeException('Backup remoto ausente; não será recriado automaticamente.');
                if (!is_string($state['last_local_digest']??null)||
                    !is_string($state['last_remote_digest']??null)) {
                    throw new RuntimeException('Histórico de sincronização incompleto.');
                }
                $remotePayload=SettingsSnapshot::validated(
                    SettingsVault::open($remote['raw'],$key,$scope),
                    $state['include_credentials']
                );
                $local=SettingsSnapshot::fromStore($this->store,$state['include_credentials']);
                $localHash=SettingsSnapshot::digest($local);
                $remoteHash=SettingsSnapshot::digest($remotePayload);
                $localChanged=$localHash!==$state['last_local_digest'];
                $remoteChanged=$remoteHash!==$state['last_remote_digest'];
                if ($localChanged && $remoteChanged) {
                    $state=self::saved($state,'conflict',etag:$remote['etag']);
                    $this->store->write(self::STATE,$state);
                    return 'conflict';
                }
                if ($remoteChanged &&
                    SettingsSnapshot::wouldReplaceActiveCredentials($this->store,$remotePayload)) {
                    $state=self::saved($state,'conflict',etag:$remote['etag']);
                    $this->store->write(self::STATE,$state);
                    return 'conflict';
                }
                if ($remoteChanged) {
                    SettingsSnapshot::restore($this->store,$remotePayload);
                    $localHash=SettingsSnapshot::digest(
                        SettingsSnapshot::fromStore($this->store,$state['include_credentials'])
                    );
                    $label='restored';
                    $state=self::saved($state,'synced',$localHash,$remoteHash,$remote['etag']);
                } elseif ($localChanged) {
                    $written=$this->dav->write(
                        $creds,SettingsVault::seal($local,$key,$salt,$scope),$remote['etag']
                    );
                    $label='uploaded';
                    $state=self::saved($state,'synced',$localHash,$localHash,$written['etag']);
                } else {
                    $label='unchanged';
                    $state=self::saved($state,'synced',$localHash,$remoteHash,$remote['etag']);
                }
                $this->store->write(self::STATE,$state);
                return $label;
            } catch (SettingsSyncConflict) {
                $state=self::saved($state,'conflict');
                $this->store->write(self::STATE,$state);
                return 'conflict';
            } finally {sodium_memzero($key);}
        });
    }

    /** Stops the local sync and forgets its cached key; remote backup remains. */
    public function disable(): void
    {
        $this->withLock(function(): void {
            $this->store->write(self::STATE,['enabled'=>false,'state'=>'disabled']);
            $this->store->write(self::KEY,[]);
        });
    }
}
