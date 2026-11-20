<?php

declare(strict_types=1);

namespace App\AutomaticKimai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Read-only, unauthenticated lookup of the official stable public release. */
final class OfficialRelease
{
    public const API_URL='https://api.github.com/repos/LibreCodeCoop/automatic-kimai/releases/latest';
    public const RELEASES_URL='https://github.com/LibreCodeCoop/automatic-kimai/releases/latest';

    public function latest(): array
    {
        try {
            $response=Http::acceptJson()
                ->withHeaders(['User-Agent'=>'Automatic-Kimai-Version-Check'])
                ->withoutRedirecting()
                ->timeout(6)
                ->get(self::API_URL);
            if (!$response->successful()) {
                throw new RuntimeException('Release unavailable');
            }
            $data=$response->json();
            if (!is_array($data) ||
                ($data['draft']??null)!==false ||
                ($data['prerelease']??null)!==false ||
                !is_string($data['tag_name']??null) ||
                !preg_match('/^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D',$data['tag_name'])) {
                throw new RuntimeException('Invalid stable release');
            }
            return [
                'version'=>substr($data['tag_name'],1),
                'url'=>self::RELEASES_URL,
            ];
        } catch (\Throwable $error) {
            // Never pass provider response content, local hostnames or tokens
            // to public HTTP clients or to application logs.
            throw new RuntimeException('Release unavailable',0,$error);
        }
    }
}
