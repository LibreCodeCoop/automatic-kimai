<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Bounded, on-demand group picker. Fetches one Telegram dialogs page per
 * request, with encrypted, time-limited cursors bound to the same account.
 * Messages and the complete dialog list are never fetched for this screen.
 */
final class TelegramGroupSelection
{
    private const CURSOR_TTL=1200;

    public function __construct(private readonly LocalStore $store) {}

    private function adapter(): MadelineTelegramAdapter
    {
        $credentials=$this->store->credentials();
        if (!isset($credentials['TELEGRAM_API_ID'],$credentials['TELEGRAM_API_HASH'])) {
            throw new RuntimeException('Conecte seu Telegram antes de selecionar grupos');
        }
        return new MadelineTelegramAdapter(
            $this->store->root().'/telegram-user.session',
            (int)$credentials['TELEGRAM_API_ID'],
            (string)$credentials['TELEGRAM_API_HASH'],
        );
    }

    private static function parseCursor(string $token,int $account): array
    {
        if ($token==='' || strlen($token)>2048) {
            throw new InvalidArgumentException('Cursor de paginação inválido');
        }
        try {
            $value=json_decode(Crypt::decryptString($token),true,8,JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidArgumentException('Cursor de paginação inválido');
        }
        if (!is_array($value)||($value['v']??null)!==1||
            ($value['account']??null)!==$account ||
            !is_int($value['issued']??null)||
            $value['issued']>time()+30||
            $value['issued']<time()-self::CURSOR_TTL) {
            throw new InvalidArgumentException('A paginação expirou; atualize a lista de grupos');
        }
        $offset=$value['offset']??null;
        if (!is_array($offset) ||
            !is_int($offset['date']??null)||$offset['date']<0||
            !is_int($offset['id']??null)||$offset['id']<1||
            !is_int($offset['peer']??null)||$offset['peer']===0) {
            throw new InvalidArgumentException('Cursor de paginação inválido');
        }
        return $offset;
    }

    private static function nextCursor(?array $offset,int $account): ?string
    {
        if ($offset===null)return null;
        return Crypt::encryptString(json_encode([
            'v'=>1, 'account'=>$account,
            'issued'=>time(), 'offset'=>$offset,
        ],JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{groups:array,recommended_groups:array,next_cursor:?string,selected_ids:array,
     *               suggestions_allowed:bool,scanned_dialog_count:int}
     */
    public function page(?string $cursor=null,?object $client=null): array
    {
        $own=$client===null;
        $client??=$this->adapter();
        try {
            $account=$client->selfId();
            if (!is_int($account)||$account<=0) {
                throw new RuntimeException('Identidade Telegram inválida');
            }
            $offset=$cursor===null?null:self::parseCursor($cursor,$account);
            $result=$client->groupsPage($offset);
            if ($offset!==null && is_array($result) &&
                ($result['next_offset']??null)===$offset) {
                throw new RuntimeException('Telegram não avançou na paginação');
            }
            $profile=$this->store->profile();
            $selected=$profile?->get('work_messenger_dialog_ids')??[];
            $labels=$profile?->get('work_messenger_labels')??[];
            $suggestionsAllowed=$selected===[] && $labels===[];
            $recommendations=$cursor===null && $suggestionsAllowed &&
                method_exists($client,'recommendedGroups')?
                $client->recommendedGroups():[];
        } finally {
            if ($own && method_exists($client,'close'))$client->close();
        }
        if (!is_array($result)||!is_array($result['groups']??null)||
            count($result['groups'])>MadelineTelegramAdapter::PAGE_SIZE+MadelineTelegramAdapter::MAX_PINNED ||
            !is_int($result['scanned_dialog_count']??null)||
            $result['scanned_dialog_count']>MadelineTelegramAdapter::PAGE_SIZE+MadelineTelegramAdapter::MAX_PINNED) {
            throw new RuntimeException('Telegram retornou uma página inválida');
        }
        if (!is_array($recommendations)||count($recommendations)>2) {
            throw new RuntimeException('Telegram retornou sugestões inválidas');
        }
        return [
            'groups'=>$result['groups'],
            'recommended_groups'=>$recommendations,
            'next_cursor'=>self::nextCursor($result['next_offset']??null,$account),
            'selected_ids'=>$selected,
            'suggestions_allowed'=>$suggestionsAllowed,
            'scanned_dialog_count'=>$result['scanned_dialog_count'],
        ];
    }

    /**
     * Validate selected groups by exact Telegram peer ID, not a 4,000-dialog
     * enumeration, not similar titles and not untrusted browser-side labels.
     */
    public function select(array $requestedIds,?object $client=null): array
    {
        $profile=$this->store->profile();
        if ($profile===null)throw new RuntimeException('Conecte sua conta Kimai primeiro');
        if ($requestedIds===[]||count($requestedIds)>40) {
            throw new InvalidArgumentException('Escolha entre 1 e 40 grupos profissionais');
        }
        $ids=[];
        foreach ($requestedIds as $input) {
            if (!is_string($input)&&!is_int($input)) {
                throw new InvalidArgumentException('Identificador de grupo inválido');
            }
            $value=(string)$input;
            if (!preg_match('/^-?[1-9][0-9]*$/D',$value) ||
                filter_var($value,FILTER_VALIDATE_INT)===false) {
                throw new InvalidArgumentException('Identificador de grupo inválido');
            }
            $id=(int)$value;
            if (isset($ids[$id]))throw new InvalidArgumentException('Grupo repetido');
            $ids[$id]=$id;
        }

        $own=$client===null;
        $client??=$this->adapter();
        try {
            $known=$client->groupDetails(array_values($ids));
        } finally {
            if ($own && method_exists($client,'close'))$client->close();
        }
        if (!is_array($known))throw new RuntimeException('Falha ao validar grupos');
        $labels=[];
        foreach ($ids as $id) {
            $group=$known[$id]??null;
            if (!is_array($group)||($group['id']??null)!==$id||
                !is_string($group['title']??null)) {
                throw new InvalidArgumentException('Grupo não encontrado ou não autorizado na conta');
            }
            $label=trim(mb_substr($group['title'],0,80));
            if (mb_strlen($label)<2)$label='Grupo Telegram';
            $labels[$label]=$label;
        }
        $settings=$profile->toArray();
        $settings['work_messenger_dialog_ids']=array_values($ids);
        $settings['work_messenger_labels']=array_values($labels);
        $updated=new Profile($settings);
        // Fail closed: previously enabled collection is suspended until
        // explicit re-activation after every change to the group allowlist.
        $this->store->setSources(['telegram'=>false]);
        $this->store->saveProfile($updated);
        return ['status'=>'saved','selected_count'=>count($ids),'enabled'=>false];
    }
}
