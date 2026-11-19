<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\ActivityWatchClient;
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\WeeklyCalendar;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\NextcloudDesktopDiscovery;
use App\AutomaticKimai\NextcloudLoginFlow;
use App\AutomaticKimai\ManualTime;
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\ReviewQueue;
use App\AutomaticKimai\SyncEngine;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

final class AutomaticKimaiController extends Controller
{
    public function __construct(private readonly LocalStore $store) {}

    private function local(Request $request): void
    {
        $host=strtolower($request->getHost());
        if (!in_array($host,['localhost','127.0.0.1','::1'],true) ||
            !in_array($request->ip(),['127.0.0.1','::1'],true)) {
            abort(403,'Automatic Kimai is accessible only from localhost');
        }
    }

    public function home(Request $request): View
    {
        $this->local($request);
        $view=(string)$request->query('view','overview');
        if ($view==='settings')$view='kimai'; // Existing bookmarks.
        if ($view==='about')$view='overview'; // Old migration-page links.
        $titles=[
            'overview'=>'Visão geral',
            'review'=>'Conciliação de horas',
            'calendar'=>'Calendário semanal',
            'attendance'=>'Trabalho presencial',
            'manual'=>'Informar horas',
            'kimai'=>'Conta Kimai',
            'sources'=>'Fontes de dados',
            'integrations'=>'Conexões',
            'nextcloud'=>'Nextcloud',
            'telegram'=>'Telegram',
            'github'=>'GitHub',
        ];
        $hints=[
            'overview'=>'Acompanhe seu monitoramento e veja o que precisa de atenção.',
            'review'=>'Acompanhe horas reconciliadas e observações monitoradas automaticamente.',
            'calendar'=>'Compare lançamentos confirmados e evidências que ainda precisam de revisão.',
            'attendance'=>'Revise compromissos presenciais e desconte as pausas.',
            'manual'=>'Informe atividades realizadas fora da coleta automática.',
            'kimai'=>'Visualize sua conta e altere a conexão apenas se necessário.',
            'sources'=>'Controle quais informações o aplicativo pode consultar.',
            'integrations'=>'Conecte cada serviço separadamente.',
            'nextcloud'=>'Autorize o acesso no navegador sem usar sua senha principal.',
            'telegram'=>'Escolha as conversas profissionais que deseja autorizar.',
            'github'=>'Metadados de atividades técnicas, sem presumir horas.',
        ];
        if (!array_key_exists($view,$titles))$view='overview';
        $sources=$this->store->sources();
        $connectionVisibility=[
            'nextcloud'=>$sources['nextcloud_calendar']||$sources['nextcloud_talk']||
                $sources['nextcloud_activity']||$sources['nextcloud_mail']||
                (!empty($this->store->credentials()['NEXTCLOUD_APP_PASSWORD'])),
            'github'=>$sources['github'],
            // Requesting setup does NOT start Telegram collection. Its
            // authenticated scanner requires an independent authorization.
            'telegram'=>$sources['telegram_setup']||$sources['telegram'],
        ];
        $hiddenConnection=null;
        if (array_key_exists($view,$connectionVisibility) && !$connectionVisibility[$view]) {
            $hiddenConnection=$titles[$view];
            $view='sources';
        }
        if ($view==='integrations' && !in_array(true,$connectionVisibility,true)) {
            $hiddenConnection='Conexões';
            $view='sources';
        }
        $status=$this->store->status();
        $statusLabel=match($status['result']??'never_ran') {
            'success'=>'Atualizado',
            'capture_only'=>'Dados coletados',
            'never_ran'=>'Sem análise',
            default=>'Verificar análise',
        };
        $lastAnalysis='ainda não executada';
        if (is_string($status['at_local']??null)) {
            try {
                $at=new DateTimeImmutable($status['at_local']);
                $lastAnalysis=$at->format('d/m/Y').' às '.$at->format('H:i');
            } catch (Throwable) {}
        }
        $reviewRows=[];
        if ($view==='review' && $this->store->profile()!==null) {
            // Rendering local evidence must never wait for a network request.
            // Load the remote catalog on demand only when a review form opens.
            $queue=new ReviewQueue($this->store->reviewQueueFile());
            try {$reviewRows=$queue->rows();}
            finally {$queue->close();}
        }
        $calendar=null;
        if ($view==='calendar') {
            $day=(string)$request->query('week','');
            if ($day!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)) {
                abort(422,'Semana inválida');
            }
            $profile=$this->store->profile();
            $zone=new \DateTimeZone((string)($profile?->get('timezone')??'UTC'));
            try {
                $calendar=WeeklyCalendar::week($day==='' ? (new DateTimeImmutable('now',$zone))->format('Y-m-d') : $day,$zone);
            } catch (\Throwable) {abort(422,'Semana inválida');}
            $calendar['events']=[];
            $calendar['error']=null;
            $calendar['outdated']=false;
            if ($profile===null) {
                $calendar['error']='Conecte uma conta Kimai para consultar as horas registradas.';
            } else {
                try {
                    $creds=$this->store->credentials();
                    $api=new KimaiClient($creds['KIMAI_BASE_URL']??'', $creds['KIMAI_API_TOKEN']??'');
                    $me=$api->me();
                    if (!is_int($me['id']??null))throw new RuntimeException('Conta Kimai sem identificação');
                    $remote=$api->timesheets($me['id'],$calendar['days'],$zone);
                    $calendar['events']=WeeklyCalendar::entries($remote,$this->store->report(),$calendar['days'],$zone);
                } catch (Throwable) {
                    $calendar['error']='Não foi possível consultar os lançamentos do Kimai. Confira a conexão e tente novamente.';
                    $calendar['outdated']=true;
                    $calendar['events']=WeeklyCalendar::entries([],$this->store->report(),$calendar['days'],$zone);
                }
            }
            $calendar=WeeklyCalendar::decorate($calendar);
        }
        return view('automatic-kimai.dashboard',[
            'reviewRows'=>$reviewRows,
            'calendar'=>$calendar,
            'view'=>$view,
            'statusLabel'=>$statusLabel,
            'lastAnalysis'=>$lastAnalysis,
            'pageTitles'=>$titles,
            'pageHints'=>$hints,
            'desktopNextcloudServers'=>$view==='nextcloud'?NextcloudDesktopDiscovery::discover():[],
            'profile'=>$this->store->profile(),
            'creds'=>$this->store->credentials(),
            'sources'=>$sources,
            'connectionVisibility'=>$connectionVisibility,
            'hiddenConnection'=>$hiddenConnection,
            'report'=>$this->store->report(),
            'status'=>$status,
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $this->local($request);
        $data=$request->validate([
            'profile_id'=>'required|regex:/^[a-z0-9][a-z0-9_-]{1,47}$/',
            'timezone'=>'required|string|max:100',
            'kimai_url'=>'required|url|max:250',
            'kimai_token'=>'nullable|string|max:800',
            'customer_id'=>'required|integer|min:1',
            'project_id'=>'required|integer|min:1',
            'activity_id'=>'required|integer|min:1',
            'work_domains'=>'nullable|string|max:2000',
        ]);
        try {
            $original=$this->store->credentials();
            $token=$data['kimai_token']??'';
            if ($token==='')$token=$original['KIMAI_API_TOKEN']??'';
            if ($token==='')throw new RuntimeException('Create an individual Kimai API token before saving');
            $domains=array_values(array_filter(array_map(
                static fn($x)=>strtolower(trim($x)),
                explode(',',(string)($data['work_domains']??'')))));
            // Do not wipe explicitly approved Telegram conversation labels
            // when the Kimai connection form is saved again.
            $currentProfile=$this->store->profile();
            $profile=new Profile(array_replace($currentProfile?->toArray()??[],[
                'profile_id'=>$data['profile_id'],'timezone'=>$data['timezone'],
                'customer_id'=>(int)$data['customer_id'],
                'project_id'=>(int)$data['project_id'],
                'activity_id'=>(int)$data['activity_id'],
                'work_domains'=>$domains,
                'sync_mode'=>'review_only',
                'allow_auto_nonbillable_preview'=>false,
            ]));
            $api=new KimaiClient($data['kimai_url'],$token);
            $me=$api->me();
            if (($me['timezone']??null)!==$profile->get('timezone') ||
                !is_int($me['id']??null)) {
                throw new RuntimeException('Timezone differs from the Kimai account settings');
            }
            $api->validateCatalog($profile);
            $this->store->saveCredentials([
                'KIMAI_BASE_URL'=>$data['kimai_url'],
                'KIMAI_API_TOKEN'=>$token,
                'KIMAI_WRITE_ENABLED'=>'false',
                'KIMAI_AUTO_PREVIEW_OPT_IN'=>'false',
            ]);
            $this->store->saveProfile($profile);
            return back()->with('ok','Kimai connection and profile verified. The application remains read-only.');
        } catch(Throwable $e) {
            return back()->withErrors(['setup'=>$e->getMessage()]);
        }
    }

    public function catalog(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->local($request);
        $creds=$this->store->credentials();
        try {
            $api=new KimaiClient($creds['KIMAI_BASE_URL']??'', $creds['KIMAI_API_TOKEN']??'');
            return response()->json($api->catalog());
        } catch(Throwable) {
            return response()->json(['error'=>'Set up and test the account first'],422);
        }
    }

    public function catalogPreview(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->local($request);
        $input=$request->validate([
            'kimai_url'=>'required|url|max:250',
            'kimai_token'=>'nullable|string|max:800',
        ]);
        $current=$this->store->credentials();
        $token=$input['kimai_token']??'';
        if ($token==='')$token=$current['KIMAI_API_TOKEN']??'';
        try {
            $api=new KimaiClient($input['kimai_url'],$token);
            $me=$api->me();
            $catalog=$api->catalog();
            return response()->json([
                'timezone'=>$me['timezone']??null,
                'customers'=>$catalog['customers'],
                'projects'=>$catalog['projects'],
                'activities'=>$catalog['activities'],
            ]);
        } catch(Throwable $e) {
            return response()->json([
                'error'=>'Não foi possível carregar os projetos. Confira o endereço e o token individual.'
            ],422);
        }
    }

    public function connectors(Request $request): RedirectResponse
    {
        $this->local($request);
        $input=$request->validate([
            'nextcloud_url'=>'nullable|url|max:250',
            'nextcloud_user'=>'nullable|string|max:200',
            'nextcloud_password'=>'nullable|string|max:500',
            'github_token'=>'nullable|string|max:500',
        ]);
        try {
            $credentials=[];
            $manual=isset($input['nextcloud_url']) || isset($input['nextcloud_user']) ||
                isset($input['nextcloud_password']);
            if ($manual) {
                $enabled=$this->store->sources();
                if (!$enabled['nextcloud_calendar'] && !$enabled['nextcloud_talk'] &&
                    !$enabled['nextcloud_activity'] && !$enabled['nextcloud_mail'] &&
                    empty($this->store->credentials()['NEXTCLOUD_APP_PASSWORD'])) {
                    throw new RuntimeException('Ative ao menos um aplicativo Nextcloud antes de conectar.');
                }
                $existing=$this->store->credentials();
                $server=NextcloudLoginFlow::normalizeServer((string)($input['nextcloud_url']??''));
                $login=trim((string)($input['nextcloud_user']??''));
                $pass=(string)($input['nextcloud_password']??'');
                if ($pass==='' && $server===($existing['NEXTCLOUD_URL']??null) &&
                    $login===($existing['NEXTCLOUD_LOGIN_NAME']??$existing['NEXTCLOUD_USERNAME']??null)) {
                    $pass=(string)($existing['NEXTCLOUD_APP_PASSWORD']??'');
                }
                if ($pass==='')throw new RuntimeException('Informe uma senha de aplicativo do Nextcloud.');
                $uid=NextcloudLoginFlow::verifyIdentity($server,$login,$pass);
                $credentials=[
                    'NEXTCLOUD_URL'=>$server,
                    'NEXTCLOUD_LOGIN_NAME'=>$login,
                    'NEXTCLOUD_USERNAME'=>$uid,
                    'NEXTCLOUD_APP_PASSWORD'=>$pass,
                ];
            }
            if (!empty($input['github_token'])) {
                if (!$this->store->sources()['github']) {
                    throw new RuntimeException('Ative GitHub em Fontes de dados antes de conectar.');
                }
                $credentials['GITHUB_TOKEN']=$input['github_token'];
            }
            if ($credentials===[])return back()->with('ok','Nenhuma conexão foi alterada.');
            $this->store->saveCredentials($credentials);
            return back()->with('ok','Credenciais salvas localmente. Confira a identidade e o estado da conta na página da conexão.');
        } catch(Throwable $e) {
            return back()->withErrors(['connectors'=>$e->getMessage()]);
        }
    }

    public function attendanceSubmit(Request $request): RedirectResponse
    {
        $this->local($request);
        $input=$request->validate([
            'day'=>'required|date_format:Y-m-d','label'=>'required|string|min:3|max:64',
            'start'=>'required|date_format:H:i','end'=>'required|date_format:H:i',
            'breaks'=>'nullable|string|max:200',
            'pause_start'=>'nullable|date_format:H:i|required_with:pause_end',
            'pause_end'=>'nullable|date_format:H:i|required_with:pause_start',
            'schedule_source'=>'required|string|max:150',
            'attended'=>'accepted',
        ]);
        $profile=$this->store->profile();
        if (!$profile)return back()->withErrors(['attendance'=>'Conecte sua conta antes de registrar presença']);
        try {
            $breaks=[];
            foreach (explode(',',(string)($input['breaks']??'')) as $text) {
                $text=trim($text);
                if ($text==='')continue;
                if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d-([01]\\d|2[0-3]):[0-5]\\d$/D',$text)) {
                    throw new RuntimeException('Cada pausa deve ser informada no formato 12:00-13:00');
                }
                $breaks[]=explode('-',$text);
            }
            if (!empty($input['pause_start']) && !empty($input['pause_end'])) {
                $breaks[]=[$input['pause_start'],$input['pause_end']];
            }
            $sections=ManualTime::attendanceSegments($profile,[
                'day'=>$input['day'],'start'=>$input['start'],'end'=>$input['end'],
                'breaks'=>$breaks,'label'=>$input['label'],
                'schedule_source'=>$input['schedule_source'],
                'attendance_attested'=>true,
            ],new DateTimeImmutable('now',$profile->zone));
            $creds=$this->store->credentials();
            $api=new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            $user=$api->me()['id']??null;
            if (!is_int($user)||$user<1)throw new RuntimeException('Kimai account unavailable');
            \App\AutomaticKimai\PublicationGate::assertManualReady(
                $this->store,$profile,$creds,$user);
            $created=[];$manual=new ManualTime();
            foreach ($sections as [$start,$end]) {
                // Each subinterval is independently idempotent. If a later
                // segment fails, an explicit retry does not double-post
                // those already confirmed.
                $result=$manual->submit($profile,$api,$this->store->ledgerFile(),
                    true,$input['day'],$start,$end,$input['label'],true,
                    new DateTimeImmutable('now',$profile->zone),
                    'reported_attendance_scheduled_bounds');
                $created[]=(int)$result['remote_id'];
            }
            return back()->with('ok','Presença registrada no Kimai. IDs: '.
                implode(', ',$created));
        } catch(Throwable $e) {
            return back()->withErrors(['attendance'=>$e->getMessage()]);
        }
    }

    public function sources(Request $request): RedirectResponse
    {
        $this->local($request);
        try {
            $current=$this->store->sources();
            $next=[];
            foreach ($current as $key=>$value) {
                // Telegram's scanner remains separately activated by the QR/2FA
                // flow; selecting it here only reveals the setup screen.
                $next[$key]=$key==='telegram' ? $value :
                    ($key==='activitywatch'||$request->boolean('source_'.$key));
            }
            $this->store->setSources($next);
            return back()->with('ok','Opt-in sources saved. Only the selected integrations are consulted.');
        } catch(Throwable $e) {
            return back()->withErrors(['sources'=>$e->getMessage()]);
        }
    }

    public function capture(Request $request): RedirectResponse
    {
        $this->local($request);
        try {
            $status=(new SyncEngine($this->store))->run(false,$request->boolean('capture_only'));
            return back()->with('ok','Read-only analysis complete. '.$status['result']);
        } catch(Throwable $e) {
            return back()->withErrors(['sync'=>get_class($e).': '.$e->getMessage()]);
        }
    }

    public function manual(Request $request): RedirectResponse
    {
        $this->local($request);
        $data=$request->validate([
            'day'=>'required|date_format:Y-m-d',
            'start'=>'required|date_format:H:i',
            'end'=>'required|date_format:H:i',
            'task'=>'required|string|min:3|max:64',
            'confirmed'=>'accepted',
        ]);
        $profile=$this->store->profile();
        if (!$profile)return back()->withErrors(['manual'=>'Configure Kimai first']);
        $creds=$this->store->credentials();
        try {
            $api=new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            $user=$api->me()['id']??null;
            if (!is_int($user)||$user<1)throw new RuntimeException('Kimai account unavailable');
            \App\AutomaticKimai\PublicationGate::assertManualReady(
                $this->store,$profile,$creds,$user);
            $result=(new ManualTime())->submit($profile,$api,$this->store->ledgerFile(),
                true,
                $data['day'],$data['start'],$data['end'],$data['task'],true,
                new DateTimeImmutable('now',$profile->zone));
            return back()->with('ok','Kimai worklog entry #'.$result['remote_id'].' verified.');
        } catch(Throwable $e) {
            return back()->withErrors(['manual'=>$e->getMessage()]);
        }
    }
}
