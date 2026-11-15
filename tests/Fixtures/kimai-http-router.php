<?php
declare(strict_types=1);

// TEST ONLY: Disposable loopback HTTP double of Kimai's minimal API.
// Uses no external credentials or real remote account.
$stateFile=getenv('KIMAI_QA_STATE');
$token=getenv('KIMAI_QA_TOKEN');
if (!is_string($stateFile)||!is_file($stateFile)||!is_string($token)||
    !hash_equals('Bearer '.$token,(string)($_SERVER['HTTP_AUTHORIZATION']??''))) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo '{"error":"unauthorized"}';
    return;
}
$raw=file_get_contents($stateFile);
$state=json_decode((string)$raw,true);
if (!is_array($state)) {
    http_response_code(500);
    return;
}
$path=(string)parse_url((string)($_SERVER['REQUEST_URI']??''),PHP_URL_PATH);
$method=(string)($_SERVER['REQUEST_METHOD']??'');
$body=null;$status=200;$write=false;
switch ($method.' '.$path) {
    case 'GET /api/ping':
        $body=['ok'=>true];
        break;
    case 'GET /api/users/me':
        $body=['id'=>1,'timezone'=>'America/Sao_Paulo'];
        break;
    case 'GET /api/customers':
        $body=[['id'=>2,'name'=>'QA customer','visible'=>true]];
        break;
    case 'GET /api/projects':
        $body=[['id'=>41,'name'=>'QA project','customer'=>2,'visible'=>true]];
        break;
    case 'GET /api/activities':
        $body=[['id'=>25,'name'=>'QA activity','project'=>41,'visible'=>true]];
        break;
    case 'GET /api/timesheets':
        $body=$state['timesheets']??[];
        break;
    case 'POST /api/timesheets':
        $input=json_decode((string)file_get_contents('php://input'),true);
        if (!is_array($input)||!isset($input['begin'],$input['end'],$input['project'],
                $input['activity'],$input['description'],$input['billable'])) {
            $status=422;$body=['error'=>'invalid'];
            break;
        }
        $id=(int)($state['next_id']??100)+1;
        $state['next_id']=$id;
        $state['post_count']=(int)($state['post_count']??0)+1;
        $begin=new DateTimeImmutable($input['begin'].'-03:00');
        $end=new DateTimeImmutable($input['end'].'-03:00');
        $state['timesheets'][]=[
            'id'=>$id,'user'=>1,'project'=>$input['project'],
            'activity'=>$input['activity'],'begin'=>$begin->format('c'),
            'end'=>$end->format('c'),'billable'=>$input['billable'],
            'exported'=>false,'description'=>$input['description'],
            'duration'=>$end->getTimestamp()-$begin->getTimestamp(),
        ];
        $write=true;
        if (($state['fail_after_commit']??false)===true) {
            // Synthetic lost/unsuccessful reply AFTER the database committed.
            $status=503;$body=['error'=>'synthetic'];
        } else {
            $status=201;$body=['id'=>$id];
        }
        break;
    default:
        if ($method==='DELETE' && preg_match('~^/api/timesheets/([1-9][0-9]*)$~D',$path,$match)) {
            $state['timesheets']=array_values(array_filter(
                $state['timesheets']??[],
                static fn($row)=>$row['id']!==(int)$match[1]
            ));
            $state['delete_count']=(int)($state['delete_count']??0)+1;
            $status=204;$body=null;$write=true;
        } else {
            $status=404;$body=['error'=>'not found'];
        }
}
if ($write)file_put_contents($stateFile,json_encode($state,JSON_THROW_ON_ERROR));
http_response_code($status);
header('Cache-Control: no-store');
if ($status!==204) {
    header('Content-Type: application/json');
    echo json_encode($body,JSON_THROW_ON_ERROR);
}
