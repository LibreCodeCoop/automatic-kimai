<?php
declare(strict_types=1);

namespace App\AutomaticKimai;

use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal opt-in group picker. Listing groups never reads their messages.
 * Only selected, enumerated peer IDs can later be used by the collector.
 */
final class TelegramGroupSelection
{
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

    /**
     * @return array{groups:array<int,array{id:int,title:string}>,selected_ids:array<int,int>}
     */
    public function available(?object $client=null): array
    {
        $own=$client===null;
        $client??=$this->adapter();
        try {
            $raw=$client->groups();
        } finally {
            if ($own && method_exists($client,'close'))$client->close();
        }
        if (!is_array($raw) || count($raw)>4000) {
            throw new RuntimeException('A lista de grupos do Telegram excedeu o limite seguro.');
        }
        $groups=[];
        foreach ($raw as $item) {
            if (!is_array($item)||!is_int($item['id']??null)||$item['id']===0||
                !is_string($item['title']??null))continue;
            $title=trim(mb_substr($item['title'],0,150));
            if ($title==='')continue;
            $groups[$item['id']]=['id'=>$item['id'],'title'=>$title];
        }
        $groups=array_values($groups);
        usort($groups,static fn($a,$b)=>
            strcasecmp($a['title'],$b['title']) ?: ($a['id']<=>$b['id']));
        return [
            'groups'=>$groups,
            'selected_ids'=>$this->store->profile()?->get('work_messenger_dialog_ids')??[],
        ];
    }

    /** Store an explicit, exact-ID allowlist. Re-authorization never silently activates collection. */
    public function select(array $requestedIds,?object $client=null): array
    {
        $profile=$this->store->profile();
        if ($profile===null)throw new RuntimeException('Conecte sua conta Kimai primeiro');
        if ($requestedIds===[]||count($requestedIds)>40) {
            throw new InvalidArgumentException('Escolha entre 1 e 40 grupos profissionais');
        }
        $ids=[];
        foreach ($requestedIds as $input) {
            if (!is_string($input) && !is_int($input)) {
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
        $available=$this->available($client);
        $known=[];
        foreach ($available['groups'] as $group)$known[$group['id']]=$group;
        $labels=[];
        foreach ($ids as $id) {
            if (!isset($known[$id])) {
                throw new InvalidArgumentException('Grupo não encontrado na conta autenticada');
            }
            $label=trim(mb_substr($known[$id]['title'],0,80));
            if (mb_strlen($label)<2)$label='Grupo Telegram';
            $labels[$label]=$label;
        }
        $settings=$profile->toArray();
        $settings['work_messenger_dialog_ids']=array_values($ids);
        $settings['work_messenger_labels']=array_values($labels);
        $updated=new Profile($settings);
        // Fail closed on editing: the scanner must be explicitly re-enabled.
        $this->store->setSources(['telegram'=>false]);
        $this->store->saveProfile($updated);
        return [
            'status'=>'saved',
            'selected_count'=>count($ids),
            'enabled'=>false,
        ];
    }
}
