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

    private static function parseCursor(string $token,int $account,int $folder,?string $query=null): array
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
            ($value['folder']??null)!==$folder ||
            ($value['q']??null)!==($query===null ? null : hash('sha256',$query)) ||
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

    private static function nextCursor(?array $offset,int $account,int $folder,?string $query=null): ?string
    {
        if ($offset===null)return null;
        return Crypt::encryptString(json_encode([
            'v'=>1, 'account'=>$account, 'folder'=>$folder,
            'issued'=>time(), 'offset'=>$offset,
            'q'=>$query===null?null:hash('sha256',$query),
        ],JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{groups:array,recommended_groups:array,next_cursor:?string,selected_ids:array,
     *               suggestions_allowed:bool,scanned_dialog_count:int}
     */
    public function page(?string $cursor=null,?object $client=null,int $folder=0): array
    {
        if (!in_array($folder,[0,1],true)) {
            throw new InvalidArgumentException('Pasta Telegram inválida');
        }
        $own=$client===null;
        $client??=$this->adapter();
        try {
            $account=$client->selfId();
            if (!is_int($account)||$account<=0) {
                throw new RuntimeException('Identidade Telegram inválida');
            }
            $offset=$cursor===null?null:self::parseCursor($cursor,$account,$folder);
            $result=$client->groupsPage($offset,$folder);
            if ($offset!==null && is_array($result) &&
                ($result['next_offset']??null)===$offset) {
                throw new RuntimeException('Telegram não avançou na paginação');
            }
            $profile=$this->store->profile();
            $selected=$profile?->get('work_messenger_dialog_ids')??[];
            // Offer each canonical joined community once, even for users
            // who already saved other groups in an older profile. After the
            // first explicit Save, never re-add a deliberately unchecked one.
            $suggestionsAllowed=$profile?->get('work_messenger_recommendations_reviewed')!==true;
            $recommendations=$cursor===null && $suggestionsAllowed &&
                method_exists($client,'recommendedGroups')?
                $client->recommendedGroups():[];
            $selectedGroups=[];
            if ($cursor===null) {
                $titles=$profile?->get('work_messenger_group_titles')??[];
                $known=[];
                foreach (array_merge($result['groups']??[],$recommendations) as $group) {
                    if (is_array($group) && is_int($group['id']??null)) {
                        $known[$group['id']]=$group;
                    }
                }
                $missing=[];
                foreach ($selected as $id) {
                    if (isset($known[$id]))continue;
                    if (isset($titles[(string)$id])) {
                        $known[$id]=['id'=>$id,'title'=>$titles[(string)$id]];
                    } else $missing[]=$id;
                }
                // Profiles from older versions lack the name cache.
                // Resolve at most 5 missing IDs on first load, never
                // enumerate all dialogs or fetch any message history.
                if ($missing!==[] && method_exists($client,'groupDetails')) {
                    $known+= $client->groupDetails(array_slice($missing,0,5));
                }
                foreach ($selected as $id) {
                    $selectedGroups[]=$known[$id]??[
                        'id'=>$id,'title'=>'Grupo já autorizado',
                        'recommended'=>false,'archived'=>null,
                    ];
                }
            }
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
            'selected_groups'=>$selectedGroups,
            'folder'=>$folder,
            'next_cursor'=>self::nextCursor($result['next_offset']??null,$account,$folder),
            'selected_ids'=>$selected,
            'suggestions_allowed'=>$suggestionsAllowed,
            'scanned_dialog_count'=>$result['scanned_dialog_count'],
        ];
    }

    /**
     * Explicit account-wide search, not a filter over the first loaded page.
     * Scans at most 5 x 100 dialog headers per request (no history); callers
     * continue with a query-bound encrypted cursor until both folders end.
     */
    public function search(string $query,?string $cursor=null,?object $client=null,int $folder=0): array
    {
        $query=trim($query);
        if (mb_strlen($query)<2||mb_strlen($query)>80||
            preg_match('/[\\x00-\\x1F\\x7F]/u',$query)===1||
            !in_array($folder,[0,1],true)) {
            throw new InvalidArgumentException('Informe pelo menos dois caracteres para buscar grupos.');
        }
        $normalized=mb_strtolower($query,'UTF-8');
        $own=$client===null;
        $client??=$this->adapter();
        try {
            $account=$client->selfId();
            if (!is_int($account)||$account<=0)throw new RuntimeException('Conta inválida');
            $offset=$cursor===null?null:self::parseCursor($cursor,$account,$folder,$normalized);
            $matched=[];$scanned=0;$next=null;
            for ($i=0;$i<5;$i++) {
                $result=$client->groupsPage($offset,$folder,100);
                if (!is_array($result)||!is_array($result['groups']??null)||
                    !is_int($result['scanned_dialog_count']??null)||
                    count($result['groups'])>200||
                    $result['scanned_dialog_count']>200) {
                    throw new RuntimeException('Resposta Telegram inválida');
                }
                $scanned+=$result['scanned_dialog_count'];
                foreach ($result['groups'] as $group) {
                    if (!is_array($group)||!is_int($group['id']??null)||
                        !is_string($group['title']??null))continue;
                    if (mb_stripos($group['title'],$query,0,'UTF-8')!==false) {
                        $matched[$group['id']]=$group;
                    }
                }
                $next=$result['next_offset']??null;
                if ($next===null)break;
                if (!is_array($next)||$next===$offset) {
                    throw new RuntimeException('Busca Telegram sem avanço');
                }
                $offset=$next;
            }
        } finally {
            if ($own && method_exists($client,'close'))$client->close();
        }
        return [
            'status'=>'ok','groups'=>array_values($matched),
            'folder'=>$folder,'next_cursor'=>self::nextCursor($next,$account,$folder,$normalized),
            'scanned_dialog_count'=>$scanned,
        ];
    }

    /** Native community containers, including only joined selectable child groups. */
    public function communities(?object $client=null): array
    {
        $own=$client===null;
        $client??=$this->adapter();
        try {
            $items=method_exists($client,'communitiesCatalog') ?
                $client->communitiesCatalog():[];
            if (!is_array($items)||count($items)>15)throw new RuntimeException('Comunidades inválidas');
            return $items;
        } finally {
            if ($own && method_exists($client,'close'))$client->close();
        }
    }

    /**
     * Validate selected groups by exact Telegram peer ID, not a 4,000-dialog
     * enumeration, not similar titles and not untrusted browser-side labels.
     */
    public function select(array $requestedIds,?object $client=null): array
    {
        $profile=$this->store->profile();
        if ($profile===null)throw new RuntimeException('Conecte sua conta Kimai primeiro');
        if (count($requestedIds)>40) {
            throw new InvalidArgumentException('Escolha até 40 grupos profissionais');
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

        $known=[];
        if ($ids!==[]) {
            $own=$client===null;
            $client??=$this->adapter();
            try {
                $known=$client->groupDetails(array_values($ids));
            } finally {
                if ($own && method_exists($client,'close'))$client->close();
            }
        }
        if (!is_array($known))throw new RuntimeException('Falha ao validar grupos');
        $labels=[];
        $titles=[];
        foreach ($ids as $id) {
            $group=$known[$id]??null;
            if (!is_array($group)||($group['id']??null)!==$id||
                !is_string($group['title']??null)) {
                throw new InvalidArgumentException('Grupo não encontrado ou não autorizado na conta');
            }
            $label=trim(mb_substr($group['title'],0,80));
            if (mb_strlen($label)<2)$label='Grupo Telegram';
            $labels[$label]=$label;
            $titles[(string)$id]=trim(mb_substr($group['title'],0,150));
        }
        $settings=$profile->toArray();
        $settings['work_messenger_dialog_ids']=array_values($ids);
        $settings['work_messenger_labels']=array_values($labels);
        $settings['work_messenger_group_titles']=$titles;
        $settings['work_messenger_recommendations_reviewed']=true;
        $updated=new Profile($settings);
        // Fail closed: previously enabled collection is suspended until
        // explicit re-activation after every change to the group allowlist.
        $this->store->setSources(['telegram'=>false]);
        $this->store->saveProfile($updated);
        return ['status'=>'saved','selected_count'=>count($ids),'enabled'=>false];
    }
}
