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

    public function test_explicit_restore_recreates_deleted_preview_once_and_rebinds_ledger(): void
    {
        $p=$this->profile(true);$w=$this->window();$engine=new Reconciler();
        $dir=sys_get_temp_dir().'/kimai-restore-'.bin2hex(random_bytes(6));
        $ledger=new Ledger($dir.'/ledger.db',1,'worker-one');
        $payload=Reconciler::payload($w,$p);
        $old=$this->remote($payload,555);
        $ledger->reserve('historical-post',$w,'post',$payload);
        $ledger->confirm('historical-post',$w,$old);
        // A worker manually deletes the already-confirmed nonbillable preview.
        $rows=[];$posts=0;
        $api=new KimaiClient('https://kimai.example.test','fake-token',
            static function($method,$path,$params,$data)use(&$rows,&$posts,$payload){
                if($method==='GET')return [
                    'status'=>200,'body'=>$rows,'headers'=>['x-total-pages'=>1]];
                if($method==='POST'&&$path==='/timesheets'){
                    $posts++;
                    $rows[]=[
                        'id'=>556,'user'=>1,'begin'=>$data['begin'].'-03:00',
                        'end'=>$data['end'].'-03:00','duration'=>3600,
                        'project'=>41,'activity'=>25,'billable'=>false,
                        'exported'=>false,'description'=>$data['description'],
                    ];
                    return ['status'=>201,'body'=>['id'=>556]];
                }
                throw new RuntimeException('Unexpected method');
            });
        try {
            $known=$ledger->known($w['id']);
            self::assertSame('review',$engine->plan($w,$p,[],$known,1)['action']);
            $repair=$engine->plan($w,$p,[],$known,1,true);
            self::assertSame('restore',$repair['action']);
            $done=$engine->execute($repair,$w,$api,$p,1,$ledger,true);
            self::assertSame('reconciled',$done['status']);
            self::assertSame(556,$done['remote_id']);
            self::assertSame(1,$posts);
            self::assertSame(556,$ledger->known($w['id'])['remote_id']);
            self::assertSame('unchanged',$engine->plan(
                $w,$p,$rows,$ledger->known($w['id']),1,true)['action']);
            // A second deletion is never silently retried after one
            // confirmed restoration; require a fresh human decision.
            $rows=[];
            self::assertTrue($ledger->restorationAttempted($w['id']));
            $again=$engine->plan($w,$p,$rows,$ledger->known($w['id']),1,
                true,$ledger->restorationAttempted($w['id']));
            self::assertSame('review',$again['action']);
            self::assertSame(1,$posts);
        } finally {
            $ledger->close();@unlink($dir.'/ledger.db');@rmdir($dir);
        }
    }

    public function test_restore_rejects_unsafe_snapshots_and_overlaps(): void
    {
        $p=$this->profile(true);$w=$this->window();$e=new Reconciler();
        $old=$this->remote(Reconciler::payload($w,$p),555);
        $known=['remote_id'=>555,'remote_snapshot'=>Reconciler::snapshot($old)];
        self::assertSame('restore',$e->plan($w,$p,[],$known,1,true)['action']);
        $old['billable']=true;
        // Billing metadata is not evidence that a user did/didn't work.
        self::assertSame('restore',$e->plan($w,$p,[],
            ['remote_id'=>555,'remote_snapshot'=>Reconciler::snapshot($old)],
            1,true)['action']);
        $old['exported']=true;
        self::assertSame('review',$e->plan($w,$p,[],
            ['remote_id'=>555,'remote_snapshot'=>Reconciler::snapshot($old)],
            1,true)['action']);
        self::assertSame('review',$e->plan($w,$p,
            [$this->remote(Reconciler::payload($w,$p),999)],$known,1,true)['action']);
        $shifted=$w;$shifted['end']='2026-11-06T10:01:00-03:00';
        self::assertSame('review',$e->plan($shifted,$p,[],$known,1,true)['action']);
    }

    public function test_known_billable_flag_is_not_an_attendance_filter(): void
    {
        $p=$this->profile(true);$w=$this->window();$r=new Reconciler();
        $row=$this->remote(Reconciler::payload($w,$p));$row['billable']=true;
        $known=['remote_id'=>$row['id'],'remote_snapshot'=>Reconciler::snapshot($row)];
        self::assertSame('unchanged',$r->plan($w,$p,[$row],$known,1)['action']);
        $row['end']='2026-11-06T09:59:00-03:00';$row['duration']=3540;
        $known=['remote_id'=>$row['id'],'remote_snapshot'=>Reconciler::snapshot($row)];
        self::assertSame('patch',$r->plan($w,$p,[$row],$known,1)['action']);
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
