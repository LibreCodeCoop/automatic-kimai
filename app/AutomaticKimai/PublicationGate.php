<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use RuntimeException;

/**
 * Production writer handoff. The persisted latch must be issued only after
 * stopping the legacy writer and reconciling its frozen ledger. No web route
 * can set it; a missing/malformed latch always means GET-only mode.
 */
final class PublicationGate
{
    public static function assertReady(LocalStore $store,Profile $profile,
                                       array $credentials,int $userId): void
    {
        if (!$profile->allowsAutomaticPublication() ||
            ($credentials['KIMAI_WRITE_ENABLED']??'')!=='true' ||
            ($credentials['KIMAI_AUTO_PREVIEW_OPT_IN']??'')!=='true') {
            throw new RuntimeException('Automatic worklog publication is not opted in');
        }
        self::assertCutover($store,$profile,$userId);
    }

    /** Manual entries require a human confirmation in the form, not auto opt-in. */
    public static function assertManualReady(LocalStore $store,Profile $profile,
                                             array $credentials,int $userId): void
    {
        if (($credentials['KIMAI_WRITE_ENABLED']??'')!=='true') {
            throw new RuntimeException('Manual publication is locked until the PHP writer is enabled');
        }
        self::assertCutover($store,$profile,$userId);
    }

    private static function assertCutover(LocalStore $store,Profile $profile,int $userId): void
    {
        $state=$store->read('cutover.json');
        if (($state['writer']??null)!=='php' ||
            ($state['supervised_cutover_completed']??null)!==true ||
            ($state['legacy_publisher_stopped']??null)!==true ||
            ($state['history_caught_up']??null)!==true ||
            ($state['verified_user_id']??null)!==$userId ||
            ($state['profile_id']??null)!==$profile->get('profile_id') ||
            !is_string($state['verified_at_utc']??null) ||
            !is_string($state['history_snapshot_sha256']??null) ||
            !preg_match('/^[a-f0-9]{64}$/D',$state['history_snapshot_sha256']) ||
            !is_string($state['verified_at_utc']) ||
            strtotime($state['verified_at_utc'])===false) {
            throw new RuntimeException('PHP publication is locked until supervised, reconciled writer handoff');
        }
        // Ongoing operation does not require re-signing every 24 hours.
        // The lock remains immutable to HTTP: a new cutover needs an
        // independent operator process and fresh credential checks.
    }
}
