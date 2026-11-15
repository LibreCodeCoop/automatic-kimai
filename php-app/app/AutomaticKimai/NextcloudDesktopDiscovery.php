<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

/**
 * Suggest only public server URLs from the Nextcloud desktop app's settings.
 * Never extract cookies, passwords, authentication tokens or user identities.
 */
final class NextcloudDesktopDiscovery
{
    public static function fromConfig(string $file): array
    {
        if (!is_file($file)||is_link($file)||filesize($file)>262144)return [];
        $rows=@file($file,FILE_IGNORE_NEW_LINES);
        if (!is_array($rows))return [];
        $accounts=false;$urls=[];
        foreach ($rows as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*$/',$line,$match)) {
                $accounts=trim($match[1])==='Accounts';
                continue;
            }
            if (!$accounts||!preg_match('/^\s*(?:\d+\\\\)?url\s*=\s*(.+)\s*$/i',$line,$match))continue;
            try {
                $url=NextcloudLoginFlow::normalizeServer(trim($match[1]));
                $urls[$url]=$url;
            } catch (\Throwable) { /* Ignore an invalid desktop account URL. */ }
        }
        return array_slice(array_values($urls),0,5);
    }

    public static function discover(): array
    {
        $home=getenv('HOME')?:'';
        $xdg=getenv('XDG_CONFIG_HOME')?:($home.'/'.'.config');
        $appdata=getenv('APPDATA')?:'';
        $files=[
            $xdg.'/Nextcloud/nextcloud.cfg',
            $home.'/Library/Preferences/Nextcloud/nextcloud.cfg',
        ];
        if ($appdata!=='')$files[]=$appdata.'/Nextcloud/nextcloud.cfg';
        $found=[];
        foreach ($files as $file) {
            foreach (self::fromConfig($file) as $url)$found[$url]=$url;
        }
        return array_slice(array_values($found),0,5);
    }
}
