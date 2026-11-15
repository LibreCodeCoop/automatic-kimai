<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
use App\AutomaticKimai\Profile;
use App\AutomaticKimai\Segmenter;
if ($argc!==4){fwrite(STDERR,"Usage: compare-python.php PROFILE_JSON CACHE_DIR EXPECTED_JSON\n");exit(2);}
[$program,$profileFile,$cacheDir,$referenceFile]=$argv;
$raw=json_decode(file_get_contents($profileFile),true,512,JSON_THROW_ON_ERROR);
$keys=['profile_id','timezone','customer_id','project_id','activity_id',
       'lookback_days','long_idle_minutes','max_bridge_minutes','min_session_minutes',
       'min_context_minutes','max_session_hours','settle_minutes','capture_freshness_minutes',
       'work_topic_hints','work_domains','work_messenger_labels','allow_snapshots',
       'alert_on_unrecorded_weekdays','sync_mode','allow_auto_nonbillable_preview',
       'auto_min_work_minutes','auto_max_unknown_fraction','auto_max_session_hours'];
$profile=new Profile(array_intersect_key($raw,array_flip($keys)));
$expected=json_decode(file_get_contents($referenceFile),true,512,JSON_THROW_ON_ERROR);
$now=new DateTimeImmutable($expected['now']);
$calFile=rtrim($cacheDir,'/').'/calendar_work_context.json';
$calendar=is_file($calFile)?(json_decode(file_get_contents($calFile),true)['events']??[]):[];
$total=0;$mismatches=0;
foreach($expected['days'] as $day=>$python){
 $obj=json_decode(file_get_contents(rtrim($cacheDir,'/').'/aw_sanitized_'.$day.'.json'),true,512,JSON_THROW_ON_ERROR);
 $windows=(new Segmenter())->segment($day,$obj['events'],$calendar,$profile,$now);
 $projection=array_map(static fn($w)=>array_intersect_key($w,array_flip([
  'id','start','end','work_seconds','chat_unknown_seconds','uncovered_seconds','evidence_digest'])),$windows);
 $total+=count($python);
 $diff=[];
 if(count($projection)!==count($python))$diff[]='window_count';
 foreach($python as $i=>$row){
  if(!isset($projection[$i])){$diff[]='missing_window';continue;}
  foreach($row as $name=>$v)if(($projection[$i][$name]??null)!==$v)$diff[]=$i.':'.$name;
 }
 if($diff!==[])$mismatches++;
 printf("COMPARISON %s Python=%d PHP=%d fields_different=%d labels=%s\n",
  $day,count($python),count($windows),count($diff),implode(',',array_slice($diff,0,12)));
}
printf("TOTAL windows=%d days_with_differences=%d\n",$total,$mismatches);
exit($mismatches===0?0:1);
