<?php
declare(strict_types=1);

namespace Tests\Unit\AutomaticKimai;

use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\Ledger;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\Reconciler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ReconcilerTest extends TestCase
{
    private function profile(bool $auto=false): Profile
    {
        return new Profile([
            'profile_id'=>'worker-one','timezone'=>'America/Sao_Paulo',
            'customer_id'=>2,'project_id'=>41,'activity_id'=>25,
            'sync_mode'=>$auto?'preview_nonbillable':'review_only',
            'allow_auto_nonbillable_preview'=>$auto,
        ]);
    }

    private function window(): array
    {
        return [
            'id'=>'test-session-00000001','date'=>'2026-11-06',
            'start'=>'2026-11-06T09:00:00-03:00','end'=>'2026-11-06T10:00:00-03:00',
            'work_seconds'=>3600,'chat_unknown_seconds'=>0,
            'uncovered_seconds'=>0,'topics'=>[],
            'ongoing'=>false,'evidence_digest'=>str_repeat('a',64),
        ];
    }

    private function remote(array $payload,int $id=555): array
    {
        return array_merge($payload,[
            'id'=>$id,'user'=>1,'duration'=>3600,'billable'=>false,'exported'=>false,
            'begin'=>$payload['begin'].'-03:00','end'=>$payload['end'].'-03:00',
        ]);
    }

    public function test_no_overlap_auto_submission_guard(): void
    {
        $p=$this->profile();
        $one=$this->window();
        $reconciler=new Reconciler();
        self::assertSame('post',$reconciler->plan($one,$p,[],null,1)['action']);
        $existing=$this->remote(Reconciler::payload($one,$p));
        self::assertSame('review',$reconciler->plan($one,$p,[$existing],null,1)['action']);
    }

    public function test_plan_protects_manual_edits_and_contract(): void
    {
        $p=$this->profile(true);$w=$this->window();
        $r=$this->remote(Reconciler::payload($w,$p));
        $known=['remote_id'=>555,'remote_snapshot'=>Reconciler::snapshot($r)];
        $reconciler=new Reconciler();
        self::assertSame('unchanged',$reconciler->plan($w,$p,[$r],$known,1)['action']);
        $r['description']='Edited by person';
        self::assertSame('review',$reconciler->plan($w,$p,[$r],$known,1)['action']);
        $r['description']=Reconciler::description($w);
        $r['exported']=true;
        self::assertSame('review',$reconciler->plan($w,$p,[$r],
            ['remote_id'=>555,'remote_snapshot'=>Reconciler::snapshot($r)],1)['action']);
    }

    public function test_post_requires_explicit_optin_and_does_not_mutate(): void
    {
        $reconciler=new Reconciler();$p=$this->profile();
        $w=$this->window();$dir=sys_get_temp_dir().'/kimai-test-'.bin2hex(random_bytes(6));
        $ledger=new Ledger($dir.'/ledger.db',1,'worker-one');
        try {
            $api=new KimaiClient('https://kimai.example.test','private-token',
                static fn()=>throw new RuntimeException('Transport should never be used'));
            $decision=$reconciler->plan($w,$p,[],null,1);
            $this->expectException(RuntimeException::class);
            $reconciler->execute($decision,$w,$api,$p,1,$ledger);
        } finally {
            $ledger->close();
            @unlink($dir.'/ledger.db');@rmdir($dir);
        }
    }

    public function test_unknown_write_remains_in_outbox_and_never_repeats(): void
    {
        $p=$this->profile(true);$w=$this->window();
        $dir=sys_get_temp_dir().'/kimai-test-'.bin2hex(random_bytes(6));
        $ledger=new Ledger($dir.'/ledger.db',1,'worker-one');
        $postCount=0;
        $api=new KimaiClient('https://kimai.example.test','private-token',
            static function($method,$path,$params,$payload)use(&$postCount){
                if ($method==='GET')return ['status'=>200,'body'=>[],
                    'headers'=>['x-total-pages'=>1]];
                if ($method==='POST'){$postCount++;throw new RuntimeException('connection lost');}
                throw new RuntimeException('Unexpected request');
            });
        try {
            $reconciler=new Reconciler();
            $decision=$reconciler->plan($w,$p,[],null,1);
            try {$reconciler->execute($decision,$w,$api,$p,1,$ledger);}
            catch(RuntimeException $e){self::assertStringContainsString('unknown',$e->getMessage());}
            self::assertCount(1,$ledger->pending());
            self::assertSame('unknown',$ledger->pending()[0]['state']);
            try {$reconciler->execute($decision,$w,$api,$p,1,$ledger);}
            catch(RuntimeException $e){self::assertStringContainsString('outbox',$e->getMessage());}
            self::assertSame(1,$postCount);
        } finally {
            $ledger->close();
            @unlink($dir.'/ledger.db');@rmdir($dir);
        }
    }

    public function test_known_nonbillable_record_can_be_extended_same_id(): void
    {
        $p=$this->profile(true);$w=$this->window();
        $dir=sys_get_temp_dir().'/kimai-test-'.bin2hex(random_bytes(6));
        $ledger=new Ledger($dir.'/ledger.db',1,'worker-one');
        $reconciler=new Reconciler();
        $old=$this->remote(Reconciler::payload($w,$p));
        $old['end']='2026-11-06T09:59:00-03:00';$old['duration']=3540;
        $ledger->reserve('prior-intent',$w,'post',Reconciler::payload($w,$p));
        $ledger->confirm('prior-intent',$w,$old);
        $rows=[$old];$patchCount=0;$postCount=0;
        $api=new KimaiClient('https://kimai.example.test','private-token',
            static function($method,$path,$params,$payload)use(&$rows,&$patchCount,&$postCount) {
                if($method==='GET')return ['status'=>200,'body'=>$rows,'headers'=>['x-total-pages'=>1]];
                if($method==='POST'){$postCount++;throw new RuntimeException('POST forbidden');}
                if($method==='PATCH'){
                    $patchCount++;$rows[0]['end']=$payload['end'].'-03:00';$rows[0]['duration']=3600;
                    return ['status'=>200,'body'=>[]];
                }
                throw new RuntimeException('Unexpected API call');
            });
        try {
            $planned=$reconciler->plan($w,$p,$rows,$ledger->known($w['id']),1);
            self::assertSame('patch',$planned['action']);
            self::assertSame(555,$planned['remote_id']);
            $result=$reconciler->execute($planned,$w,$api,$p,1,$ledger);
            self::assertSame('reconciled',$result['status']);
            self::assertSame(1,$patchCount);self::assertSame(0,$postCount);
            self::assertSame('unchanged',$reconciler->plan(
                $w,$p,$rows,$ledger->known($w['id']),1)['action']);
        } finally {
            $ledger->close();@unlink($dir.'/ledger.db');@rmdir($dir);
        }
    }
}
