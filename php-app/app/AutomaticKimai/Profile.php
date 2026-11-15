<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use DateTimeZone;
use InvalidArgumentException;

/** Public per-worker settings. No credentials or hard-coded tenant IDs. */
final class Profile
{
    // No arbitrary ceiling on explicitly authorized groups. Telegram scanning
    // is limited per cycle, independently of the size of the allowlist.

    private const DEFAULTS = [
        'profile_id' => 'user', 'timezone' => 'UTC',
        'lookback_days' => 3, 'long_idle_minutes' => 10,
        'max_bridge_minutes' => 20, 'min_session_minutes' => 15,
        'min_context_minutes' => 8, 'max_session_hours' => 8,
        'settle_minutes' => 12, 'capture_freshness_minutes' => 30,
        'work_topic_hints' => [], 'work_domains' => [], 'work_messenger_labels' => [],
        'work_messenger_dialog_ids' => [],
        'work_messenger_group_titles' => [],
        'work_messenger_recommendations_reviewed' => false,
        'allow_snapshots' => false, 'alert_on_unrecorded_weekdays' => false,
        'sync_mode' => 'review_only', 'allow_auto_nonbillable_preview' => false,
        'auto_min_work_minutes' => 8, 'auto_max_unknown_fraction' => 0.55,
        'auto_max_session_hours' => 4,
    ];

    public readonly array $settings;
    public readonly DateTimeZone $zone;

    public function __construct(array $raw)
    {
        $unknown = array_diff(array_keys($raw), [
            ...array_keys(self::DEFAULTS), 'customer_id', 'project_id', 'activity_id',
            'cache_dir', 'state_dir',
        ]);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown worker profile setting');
        }
        $settings = array_replace(self::DEFAULTS, $raw);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,47}$/D', (string) $settings['profile_id'])) {
            throw new InvalidArgumentException('Invalid profile identifier');
        }
        try {
            $this->zone = new DateTimeZone((string) $settings['timezone']);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid profile timezone');
        }
        foreach (['customer_id','project_id','activity_id'] as $name) {
            if (!isset($settings[$name]) || !is_int($settings[$name]) || $settings[$name] < 1) {
                throw new InvalidArgumentException('Invalid '.$name);
            }
        }
        foreach ([
            'lookback_days' => [1, 7], 'long_idle_minutes' => [5, 60],
            'max_bridge_minutes' => [1, 30], 'min_session_minutes' => [10, 90],
            'min_context_minutes' => [3, 60], 'max_session_hours' => [1, 12],
            'settle_minutes' => [5, 60], 'capture_freshness_minutes' => [2, 180],
            'auto_min_work_minutes' => [5, 45], 'auto_max_session_hours' => [1, 8],
        ] as $key => [$low,$high]) {
            if (!is_int($settings[$key]) || $settings[$key] < $low || $settings[$key] > $high) {
                throw new InvalidArgumentException('Invalid '.$key);
            }
        }
        if (!is_float($settings['auto_max_unknown_fraction']) && !is_int($settings['auto_max_unknown_fraction'])) {
            throw new InvalidArgumentException('Invalid unknown fraction');
        }
        if ($settings['auto_max_unknown_fraction'] < 0.1 || $settings['auto_max_unknown_fraction'] > 0.6) {
            throw new InvalidArgumentException('Invalid unknown fraction');
        }
        if (!in_array($settings['sync_mode'], ['review_only','preview_nonbillable'], true)) {
            throw new InvalidArgumentException('Invalid sync mode');
        }
        if ($settings['sync_mode'] === 'preview_nonbillable' && $settings['allow_auto_nonbillable_preview'] !== true) {
            throw new InvalidArgumentException('Auto preview requires explicit worker opt-in');
        }
        foreach (['allow_snapshots','alert_on_unrecorded_weekdays','allow_auto_nonbillable_preview',
                 'work_messenger_recommendations_reviewed'] as $key) {
            if (!is_bool($settings[$key])) {
                throw new InvalidArgumentException('Invalid '.$key);
            }
        }
        if (!is_array($settings['work_topic_hints']) || !is_array($settings['work_domains']) ||
            !is_array($settings['work_messenger_labels']) ||
            !is_array($settings['work_messenger_dialog_ids']) ||
            !is_array($settings['work_messenger_group_titles'])) {
            throw new InvalidArgumentException('Invalid approved source lists');
        }
        if (count($settings['work_domains']) > 40) {
            throw new InvalidArgumentException('Too many work domains');
        }
        $allowedGroupIds=array_fill_keys($settings['work_messenger_dialog_ids'],true);
        foreach ($settings['work_domains'] as $host) {
            if (!is_string($host) || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,30}$/D', $host)) {
                throw new InvalidArgumentException('Domains must be exact lowercase DNS hosts');
            }
        }
        foreach ($settings['work_topic_hints'] as $hint) {
            if (!is_string($hint) || strlen($hint) < 2 || strlen($hint) > 64) {
                throw new InvalidArgumentException('Invalid topic hint');
            }
        }
        foreach ($settings['work_messenger_labels'] as $label) {
            if (!is_string($label) || strlen($label) < 2 || strlen($label) > 80 ||
                str_contains($label,"\r") || str_contains($label,"\n")) {
                throw new InvalidArgumentException('Invalid messenger label');
            }
        }
        foreach ($settings['work_messenger_dialog_ids'] as $id) {
            if (!is_int($id) || $id===0) {
                throw new InvalidArgumentException('Invalid approved Telegram group identifier');
            }
        }
        if (count(array_unique($settings['work_messenger_dialog_ids'])) !==
            count($settings['work_messenger_dialog_ids'])) {
            throw new InvalidArgumentException('Duplicate approved Telegram groups');
        }
        foreach ($settings['work_messenger_group_titles'] as $id=>$title) {
            if (!is_string($title) || mb_strlen($title)>150 || mb_strlen($title)<1 ||
                !preg_match('/^-?[1-9][0-9]*$/D',(string)$id) ||
                filter_var((string)$id,FILTER_VALIDATE_INT)===false ||
                !isset($allowedGroupIds[(int)$id])) {
                throw new InvalidArgumentException('Invalid cached Telegram group titles');
            }
        }
        $this->settings = $settings;
    }

    public function get(string $key): mixed
    {
        if (!array_key_exists($key, $this->settings)) {
            throw new InvalidArgumentException('Unknown profile option');
        }
        return $this->settings[$key];
    }

    public function allowsAutomaticPublication(): bool
    {
        return $this->get('sync_mode') === 'preview_nonbillable' &&
            $this->get('allow_auto_nonbillable_preview') === true;
    }

    public function toArray(): array
    {
        return $this->settings;
    }
}
