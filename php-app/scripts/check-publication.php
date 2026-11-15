<?php
declare(strict_types=1);

/**
 * Source publication gate. Run from repository root or php-app.
 * Inspects tracked files only; never prints secret matches.
 */
$root=realpath(dirname(__DIR__,2));
if (!$root) {
    fwrite(STDERR,"PUBLICATION_CHECK_FAILED root_unavailable\n");
    exit(1);
}
if (in_array('--paths-stdin',$argv,true)) {
    // Supports sandboxed PHP containers that deliberately omit git.
    // The host provides "git ls-files -z" directly via a pipe.
    $names=stream_get_contents(STDIN,8_000_001);
    if (!is_string($names)||$names===''||strlen($names)>8_000_000) {
        fwrite(STDERR,"PUBLICATION_CHECK_FAILED empty_or_invalid_path_manifest\n");
        exit(1);
    }
} else {
    $process=@proc_open(['git','-C',$root,'ls-files','-z'],[
        0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w'],
    ],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($process)) {
        fwrite(STDERR,"PUBLICATION_CHECK_FAILED git_unavailable\n");
        exit(1);
    }
    fclose($pipes[0]);
    $names=stream_get_contents($pipes[1],8_000_001);
    fclose($pipes[1]);
    stream_get_contents($pipes[2],1000);
    fclose($pipes[2]);
    if (!is_string($names)||$names===''||strlen($names)>8_000_000||
        proc_close($process)!==0) {
        fwrite(STDERR,"PUBLICATION_CHECK_FAILED git_metadata_invalid\n");
        exit(1);
    }
}
$forbiddenFile=static function(string $file): bool {
    // Repository placeholder to keep the private directory present.
    if ($file==='php-app/storage/app/private/.gitignore')return false;
    if (preg_match('/(^|\/)\.env\.example$/D',$file))return false;
    if (str_ends_with($file,'.py')||
        preg_match('~(^|/)(?:\.env(?:\..*)?|secrets\.enc|id_rsa|id_ed25519|'
        .'[^/]*\.(?:sqlite3?|db|session|pem|key|pfx|p12))$~iD',$file))return true;
    if (str_contains($file,'/.kimai_cache/') ||
        str_contains($file,'/storage/app/private/') ||
        str_starts_with($file,'.kimai_cache/'))return true;
    return false;
};
$patterns=[
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/',
    '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/',
    '/\bgithub_pat_[A-Za-z0-9_]{30,}\b/',
    '/\bsk-[A-Za-z0-9_-]{40,}\b/',
    '/\bAIza[A-Za-z0-9_-]{30,}\b/',
];
$denylist=[];
$denyFile=$root.'/.publication-denylist';
if (is_file($denyFile)) {
    if (is_link($denyFile) || (fileperms($denyFile)&0077)!==0) {
        fwrite(STDERR,"PUBLICATION_CHECK_FAILED denylist_permissions\n");
        exit(1);
    }
    foreach (file($denyFile,FILE_IGNORE_NEW_LINES)?:[] as $line) {
        $line=trim($line);
        if ($line!==''&&!str_starts_with($line,'#'))$denylist[]=$line;
    }
}
$errors=[];$count=0;
foreach (explode("\0",$names) as $relative) {
    if ($relative==='')continue;
    $count++;
    if (str_starts_with($relative,'/')||
        str_contains($relative,'../')||
        $forbiddenFile($relative)) {
        $errors[]=['path'=>$relative,'cause'=>'private_or_python_file'];
        continue;
    }
    $file=$root.'/'.$relative;
    if (is_link($file)||!is_file($file)) {
        $errors[]=['path'=>$relative,'cause'=>'unexpected_tracked_file'];
        continue;
    }
    if (filesize($file)>4_000_000)continue;
    $content=file_get_contents($file);
    if ($content===false) {
        $errors[]=['path'=>$relative,'cause'=>'unreadable'];
        continue;
    }
    if (str_contains(substr($content,0,4096),"\0"))continue;
    foreach ($patterns as $pattern) {
        if (preg_match($pattern,$content)) {
            $errors[]=['path'=>$relative,'cause'=>'secret_like_content'];
            break;
        }
    }
    foreach ($denylist as $term) {
        if (mb_stripos($content,$term)!==false) {
            $errors[]=['path'=>$relative,'cause'=>'local_denylist_match'];
            break;
        }
    }
}
if ($errors!==[]) {
    fwrite(STDERR,"PUBLICATION_CHECK_FAILED inspected=$count findings=".count($errors)."\n");
    foreach ($errors as $error) {
        fwrite(STDERR,$error['cause'].' in '.$error['path']."\n");
    }
    exit(1);
}
echo "PUBLICATION_CHECK_OK tracked=$count secret_matches=0 python_source_files=0\n";
