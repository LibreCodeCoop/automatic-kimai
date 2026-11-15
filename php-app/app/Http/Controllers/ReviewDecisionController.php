<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\ReviewQueue;
use App\AutomaticKimai\TimeMath;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Throwable;

/**
 * Local human review: freeze or reject a candidate, never POST to Kimai.
 * Remote publication remains an independent, explicit CLI operation.
 */
final class ReviewDecisionController extends Controller
{
    public function __construct(private readonly LocalStore $store) {}

    private function local(Request $request): void
    {
        if (!in_array(strtolower($request->getHost()),['localhost','127.0.0.1','::1'],true)||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Local review only');
        }
    }

    public function approve(Request $request): RedirectResponse
    {
        $this->local($request);
        $input=$request->validate([
            'candidate_id'=>['required','string','regex:/^[A-Za-z0-9_-]{8,90}$/'],
            'source_digest'=>['required','string','regex:/^[a-f0-9]{64}$/'],
            'day'=>'required|date_format:Y-m-d',
            'start'=>'required|date_format:H:i',
            'end'=>'required|date_format:H:i',
            'project_id'=>'required|integer|min:1',
            'activity_id'=>'required|integer|min:1',
            'billable'=>'required|in:no,yes',
            'description'=>'required|string|min:10|max:500',
            'confirm_duration'=>'accepted','confirm_project'=>'accepted',
            'confirm_billing'=>'accepted',
        ]);
        $profile=$this->store->profile();
        if (!$profile)return back()->withErrors(['review'=>'Configure sua conta Kimai primeiro.']);
        try {
            $creds=$this->store->credentials();
            $api=new KimaiClient($creds['KIMAI_BASE_URL']??'',
                $creds['KIMAI_API_TOKEN']??'');
            $me=$api->me();
            if (!is_int($me['id']??null)||$me['id']<1||
                ($me['timezone']??null)!==$profile->get('timezone')) {
                throw new RuntimeException('Kimai identity changed');
            }
            $catalog=$api->catalog();
            $projectId=(int)$input['project_id'];
            $activityId=(int)$input['activity_id'];
            $project=null;$activity=null;
            foreach ($catalog['projects'] as $p)if ($p['id']===$projectId)$project=$p;
            foreach ($catalog['activities'] as $a)if ($a['id']===$activityId)$activity=$a;
            if (!$project||!is_int($project['customer_id']??null)||
                ($project['visible']??true)===false||!$activity||
                !in_array($activity['project_id']??null,[null,$projectId],true)||
                ($activity['visible']??true)===false) {
                throw new RuntimeException('Project/activity not available for authenticated user');
            }
            // Convert wall-clock fields once using the verified Kimai timezone.
            $start=TimeMath::dateTime($input['day'],$input['start'],$profile->zone);
            $end=TimeMath::dateTime($input['day'],$input['end'],$profile->zone);
            $choice=[
                'source_digest'=>$input['source_digest'],
                'start'=>TimeMath::iso($start),'end'=>TimeMath::iso($end),
                'customer_id'=>$project['customer_id'],
                'project_id'=>$projectId,'activity_id'=>$activityId,
                'billable'=>$input['billable']==='yes',
                'description'=>$input['description'],
                'human_duration_confirmed'=>true,
                'project_confirmed'=>true,'billing_confirmed'=>true,
            ];
            $queue=new ReviewQueue($this->store->reviewQueueFile());
            try {
                $queue->approve($input['candidate_id'],$choice,$profile,$me['id'],
                    new DateTimeImmutable('now',$profile->zone));
            } finally {$queue->close();}
            return back()->with('ok','Período aprovado localmente. Publicação no Kimai ainda desativada nesta migração.');
        } catch (Throwable) {
            return back()->withErrors(['review'=>'Não foi possível aprovar. Atualize a análise e confira os limites, projeto e evidências.']);
        }
    }

    public function reject(Request $request): RedirectResponse
    {
        $this->local($request);
        $input=$request->validate([
            'candidate_id'=>['required','string','regex:/^[A-Za-z0-9_-]{8,90}$/'],
            'source_digest'=>['required','string','regex:/^[a-f0-9]{64}$/'],
        ]);
        try {
            $queue=new ReviewQueue($this->store->reviewQueueFile());
            try {
                $queue->reject($input['candidate_id'],$input['source_digest']);
            } finally {$queue->close();}
            return back()->with('ok','Proposta rejeitada; o histórico de evidências foi preservado.');
        } catch (Throwable) {
            return back()->withErrors(['review'=>'A proposta mudou. Atualize a análise antes de rejeitar.']);
        }
    }
}
