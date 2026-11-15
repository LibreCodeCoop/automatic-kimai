<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\AutomaticKimai\ActivityWatchClient;
use App\AutomaticKimai\KimaiClient;
use App\AutomaticKimai\LocalStore;
use App\AutomaticKimai\NextcloudDesktopDiscovery;
use App\AutomaticKimai\NextcloudLoginFlow;
use App\AutomaticKimai\ManualTime;
use App\AutomaticKimai\Profile;
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
        $titles=[
            'overview'=>'Visão geral',
            'review'=>'Revisar minhas horas',
            'attendance'=>'Trabalho presencial',
            'manual'=>'Informar horas',
            'kimai'=>'Conta Kimai',
            'sources'=>'Fontes de dados',
            'integrations'=>'Conexões',
            'nextcloud'=>'Nextcloud',
            'telegram'=>'Telegram',
            'github'=>'GitHub',
            'about'=>'Migração e segurança',
        ];
        $hints=[
            'overview'=>'Acompanhe seu monitoramento e veja o que precisa de atenção.',
            'review'=>'Confira períodos sugeridos e lacunas que precisam de confirmação.',
            'attendance'=>'Revise compromissos presenciais e desconte as pausas.',
            'manual'=>'Informe atividades realizadas fora da coleta automática.',
            'kimai'=>'Visualize sua conta e altere a conexão apenas se necessário.',
            'sources'=>'Controle quais informações o aplicativo pode consultar.',
            'integrations'=>'Conecte cada serviço separadamente.',
            'nextcloud'=>'Autorize o acesso no navegador sem usar sua senha principal.',
            'telegram'=>'Escolha as conversas profissionais que deseja autorizar.',
            'github'=>'Metadados de atividades técnicas, sem presumir horas.',
            'about'=>'O que já funciona e o que falta validar para migrar com segurança.',
        ];
        if (!array_key_exists($view,$titles))$view='overview';
        return view('automatic-kimai.dashboard',[
            'view'=>$view,
            'pageTitles'=>$titles,
            'pageHints'=>$hints,
            'desktopNextcloudServers'=>NextcloudDesktopDiscovery::discover(),
            'profile'=>$this->store->profile(),
            'creds'=>$this->store->credentials(),
            'sources'=>$this->store->sources(),
            'report'=>$this->store->report(),
            'status'=>$this->store->status(),
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
            if (!empty($input['github_token']))$credentials['GITHUB_TOKEN']=$input['github_token'];
            if ($credentials===[])return back()->with('ok','Nenhuma conexão foi alterada.');
            $this->store->saveCredentials($credentials);
            return back()->with('ok','Conexões verificadas e salvas localmente.');
        } catch(Throwable $e) {
            return back()->withErrors(['connectors'=>$e->getMessage()]);
        }
    }

    public function attendancePreview(Request $request): RedirectResponse
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
            $lines=array_map(static fn($s)=>implode('–',$s),$sections);
            return back()->with('ok','Prévia interna, sem gravação no Kimai: '.implode('; ',$lines));
        } catch(Throwable $e) {
            return back()->withErrors(['attendance'=>$e->getMessage()]);
        }
    }

    public function nextcloudSources(Request $request): RedirectResponse
    {
        $this->local($request);
        $creds=$this->store->credentials();
        if (empty($creds['NEXTCLOUD_APP_PASSWORD'])||empty($creds['NEXTCLOUD_USERNAME'])) {
            return back()->withErrors(['nextcloud'=>'Conecte sua conta Nextcloud antes de ativar essas fontes.']);
        }
        $this->store->setSources([
            'nextcloud_calendar'=>$request->boolean('calendar'),
            'nextcloud_talk'=>$request->boolean('talk'),
        ]);
        return back()->with('ok','Permissões do Nextcloud salvas.');
    }

    public function sources(Request $request): RedirectResponse
    {
        $this->local($request);
        try {
            $current=$this->store->sources();
            $next=[];
            foreach ($current as $key=>$value) {
                // Telegram is enabled only by the dedicated authenticated
                // QR / 2FA flow, not by a generic opt-in checkbox.
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
            // Keep manual writes disabled while the Python pilot is active.
            // An explicit opt-in is not sufficient to bypass an active other
            // runtime's outbox; a supervised cutover is required first.
            if (($creds['PHP_CUTOVER_APPROVED']??'')!=='true') {
                throw new RuntimeException('PHP manual publication is disabled until the Python pilot is stopped and the ledger is reconciled');
            }
            $api=new KimaiClient($creds['KIMAI_BASE_URL'],$creds['KIMAI_API_TOKEN']);
            $result=(new ManualTime())->submit($profile,$api,$this->store->ledgerFile(),
                ($creds['KIMAI_MANUAL_PREVIEW_ENABLED']??'')==='true',
                $data['day'],$data['start'],$data['end'],$data['task'],true,
                new DateTimeImmutable('now',$profile->zone));
            return back()->with('ok','Internal non-billable Kimai entry #'.$result['remote_id'].' verified.');
        } catch(Throwable $e) {
            return back()->withErrors(['manual'=>$e->getMessage()]);
        }
    }
}
