# Automatic Kimai — PHP desktop

Código PHP 8.4 + Laravel 12 + NativePHP 2/Electron. Este diretório é o núcleo da migração na branch `feat/php-migration`; a implantação definitiva depende da conclusão dos critérios de [PHP_MIGRATION.md](../PHP_MIGRATION.md).

## Rodar localmente em desenvolvimento

Requisitos: PHP 8.4+ com cURL, DOM, mbstring, PDO SQLite, SQLite3, ZIP e OpenSSL; Composer 2; Node 22+; ActivityWatch opcional no primeiro acesso. O Electron e os scripts de empacotamento precisam do Node, mas toda a lógica de negócio do novo aplicativo está em PHP.

```sh
composer install --no-interaction
cp .env.example .env
chmod 600 .env
php artisan key:generate
php artisan package:discover
php vendor/bin/phpunit
php artisan serve --host=127.0.0.1 --port=6817
```

Abra `http://127.0.0.1:6817`. Não exponha a porta na rede. Configure o seu próprio Kimai e selecione os projetos pelo nome; o modo de revisão é o padrão. A coleta ActivityWatch usa `http://127.0.0.1:5600` e um banco local de evidências. Não coloque dados particulares em fixtures de testes.

Para desenvolvimento do aplicativo desktop, após preparar o PHP:
```sh
php artisan native:install --force --publish
php artisan native:run
```

A estrutura Electron publicada está em `nativephp/electron` e é um **runtime de interface**, não o núcleo de negócio. Os executáveis devem incluir um PHP próprio; não exigir instalação manual de PHP para o usuário final. A assinatura, atualização e binários multiplataforma ainda precisam de testes reais.

**Empacotamento bloqueado por segurança:** `native:build` é recusado se houver perfil, sessão ou chave `APP_KEY` de uma instalação em uso. O empacotador NativePHP não respeita automaticamente o `.gitignore`, por isso credenciais criptografadas e a chave de descriptografia poderiam acabar dentro do instalador. Os binários de prova gerados durante a avaliação foram eliminados; o código PHP continua intacto. Antes de qualquer release, implementar chave única no primeiro acesso e validar o isolamento dos segredos no instalador.

## Abrir como aplicativo desktop Electron no Linux/KDE

A interface também pode ser aberta como **aplicativo desktop independente do navegador**, com ícone no menu do KDE e atalho na área de trabalho. A janela é fornecida pelo Electron e mantém a aplicação Laravel PHP por trás, sem incluir segredos no binário.

No computador de avaliação já configurado:

```sh
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
```

Depois, abra **Automatic Kimai** pelo menu de aplicativos ou pelo ícone da área de trabalho. Não é necessário abrir o navegador. Se o backend PHP local estiver parado, o lançador tenta reiniciar somente seu contêiner de avaliação; o piloto Python não é afetado. Consulte [desktop-preview/README.md](desktop-preview/README.md) para detalhes e limitações.

**Não confundir com uma release instalável:** este é um inicializador Electron para o backend local PHP já instalado. Um instalador independente NativePHP/Electron, com PHP embutido e chaves individuais, ainda exige a auditoria de empacotamento. Não distribuir como release a outros cooperados.

## Aparência: modo claro e escuro

No canto superior direito da janela Electron ou da interface web local, use **Aparência**:

- **Sistema**: segue automaticamente o tema atual do KDE/sistema operacional;
- **Claro**: fixa a paleta clara;
- **Escuro**: fixa a paleta escura com contrastes consistentes em campos, botões, avisos e tabelas.

Sua escolha fica salva apenas no armazenamento local da interface (`localStorage`, chave `automatic-kimai:theme`) e é restaurada ao abrir novamente o aplicativo. A preferência de cor não altera credenciais, fontes autorizadas, períodos de trabalho nem os timers. A página CSS está em `public/css/automatic-kimai.css`, com tokens para as duas paletas; testes em `tests/Feature/ThemeUiTest.php` verificam contraste WCAG AA. A interface foi conferida com Chrome em modo headless, em ambos os temas, sem acessar dados privados.

## Configurar Telegram pelo navegador (ambiente de avaliação)

1. Abra **http://127.0.0.1:6817/#telegram-setup** **no mesmo computador** onde o aplicativo está rodando.
2. Na seção **Telegram — autenticação MTProto**, verifique se as credenciais pessoais da API já estão configuradas. Se estiverem, não precisa informá-las de novo. Caso contrário, obtenha o API ID e o hash em `my.telegram.org` (sua própria conta).
3. Informe os nomes exatos dos grupos/conversas profissionais que você autoriza consultar, separados por vírgula. Salve a configuração.
4. Clique em **Gerar QR Code**. No Telegram do celular, abra **Configurações → Dispositivos → Conectar dispositivo** e escaneie o QR Code. Se expirar, gere outro.
5. Se o Telegram solicitar a senha da verificação em duas etapas, digite-a **na página local**. Não envie essa senha a outra pessoa nem a este chat.
6. Quando aparecer **Telegram autenticado**, clique em **Ativar coleta Telegram em modo de revisão**. Apenas então o conector poderá consultar as conversas autorizadas. Não há gravação de horas no Kimai por esta etapa.

Este fluxo usa um **processo PHP separado** por até cinco minutos, mantém tokens de QR Code em arquivos privados (`0600`) e transmite a senha de duas etapas para esse processo usando armazenamento local criptografado temporário; o arquivo é eliminado assim que o comando é consumido. O mesmo login é conferido por uma segunda abertura da sessão antes de ser considerado válido.

**Validação realizada:** QR Code produzido por requisição HTTP real, reconsulta da sessão e testes de autenticação não iniciada/rotas externas. **Pendente:** conclusão real do login com 2FA pelo titular e verificação da leitura MTProto autenticada. A autenticação do Python anterior não foi invalidada. O modo de avaliação PHP permanece somente leitura e a publicação de horas continua no sistema existente.

## Comandos CLI

```sh
php artisan automatic-kimai:sync --status
php artisan automatic-kimai:sync --capture-only
php artisan automatic-kimai:sync
```

Esses comandos usam o perfil local, fazem apenas leitura e mantêm caches sanitizados. `--apply` está bloqueado enquanto não existir paridade de telemetria/agentes e migração segura do ledger. Os agendadores antigos Python continuam operando na instalação já existente.

## Privacidade

A página PHP aceita conexões somente do loopback, usa CSRF nos formulários e não mostra tokens existentes. Os segredos são criptografados com a chave local da instalação e arquivos privados são restringidos a seu proprietário. O segredo de criptografia **não deve ser reutilizado entre instaladores distribuídos**; é necessário validá-lo em chaveiro por sistema operacional antes da publicação de aplicativos assinados.

Lembre-se: um agendamento ou um processo de IA rodando sozinho não equivale a horas humanas trabalhadas. A plataforma não aprova remuneração nem faturas automaticamente.

## Status da migração

Algoritmos de segmentação, lacunas, GET Kimai, proteção de escrita, ActivityWatch, Nextcloud Calendar/Talk, GitHub, Ollama e frontend Laravel têm implementação e testes. Telegram MTProto já oferece QR/2FA pela interface local, mas a coleta real depende da autenticação/ativação explícitas; o histórico de revisão foi importado, e o publicador de sessões aprovadas foi implementado e validado em transportes simulados. Ainda faltam homologação real com Kimai descartável, telemetria operacional, empacotamento multiplataforma e corte controlado antes de substituir o runtime antigo.

Este código é um **candidato de implementação** — não uma release e não uma autorização para remover os serviços Python ainda ativos.


## Interface de configuração por tarefa

O painel local divide o fluxo em telas separadas: **Visão geral**, **Revisar horas**, **Trabalho presencial**, **Informar horas**, **Conta Kimai**, **Fontes de dados**, **Contas conectadas**, **Nextcloud**, **Telegram**, **GitHub** e **Migração e segurança**. O menu lateral vira menu recolhível no celular, sem apresentar todos os formulários de uma vez.

Para conectar Nextcloud, abra **Conexões → Nextcloud**, informe o endereço HTTPS e clique em **Conectar pelo navegador**. Autorize no próprio Nextcloud usando sua sessão de navegador (quando já estiver conectada). O servidor gera uma **senha de aplicativo individual e revogável** via Login Flow v2. Ative separadamente **Agenda** e/ou **Talk** somente após a conexão. Em servidores sem Login Flow v2, há configuração manual com senha de aplicativo e validação OCS. Nenhuma senha principal, cookie de navegador ou credencial de outro aplicativo é importada.

Quando o cliente Nextcloud Desktop está instalado na mesma conta local, o aplicativo pode sugerir somente a URL conhecida no arquivo de configuração. Em contêiner isolado, esse arquivo não fica automaticamente acessível; nesse caso, informe a URL manualmente. O usuário sempre precisa confirmar o acesso.

Durante a migração, o registro automático pelo PHP permanece bloqueado. O serviço Python ativo não pode ser desligado até que todos os coletores, a fila de aprovações, o publicador, o ledger e a retomada após falhas tenham comprovado paridade. Consulte ../PHP_MIGRATION.md.


### Navegação, permissões e ícones (15/11/2026)

O menu lateral tem hierarquia com recuos: Trabalho → páginas de revisão; Fontes de dados → Conexões habilitadas → serviços; Preferências → Conta Kimai e Migração. Em telas largas o botão recolhe para uma barra de ícones; em telas pequenas abre/fecha o menu. A escolha de recolher fica somente no armazenamento local do aplicativo/navegador.

A tela **Fontes de dados** controla a visibilidade e a leitura:
- Nextcloud só aparece quando Agenda ou Talk estiver marcado. Selecionar a fonte não basta para coletar: é preciso autorizar a conta no navegador.
- GitHub só aparece quando sua fonte estiver marcada. A leitura exige a chave apropriada.
- Telegram tem a opção **Exibir conexão** separada da autorização efetiva do scanner: o checkbox não substitui QR/2FA, nem inicia leitura de conversas automaticamente.
- Desativar uma fonte oculta a conexão, bloqueia novas autenticações por essa interface e impede sua coleta; credenciais existentes são preservadas localmente, não são revogadas no serviço remoto. Para revogar senha de aplicativo Nextcloud, acesse Segurança no próprio Nextcloud.

Os ícones são um subconjunto do **Lucide Static 1.72.0** (licença ISC), servido localmente em public/icons/lucide.svg, com licença em public/icons/LUCIDE-LICENSE. Nenhum CDN ou pacote de fontes é necessário em runtime. O conjunto contém apenas os ícones utilizados pela interface, não todo o catálogo Lucide.

A interface continua **somente leitura** para publicação no Kimai. Ocultar a conexão não apaga credenciais; a prevenção de coleta é aplicada também no coletor e nos endpoints de autorização.


### Telegram: conexão primeiro, grupos depois

Em **Fontes de dados**, habilite Telegram e salve. A página **Conexões → Telegram** inicia com o fluxo principal para conectar a conta; configurações MTProto ficam recolhidas até serem necessárias. Após o QR Code (e 2FA se solicitada), o aplicativo apresenta apenas grupos e supergrupos da conta autenticada. Contatos individuais e canais de transmissão são excluídos.

A tela oferece busca e seleção múltipla de grupos (máximo de 100). A lista é obtida do Telegram **sem ler histórico de mensagens** e é mostrada apenas na sessão local autorizada. Ao salvar, o programa persiste IDs exatos dos grupos na área privada e suspende a coleta anterior. Só o botão separado **Ativar análise dos grupos** habilita a leitura de metadados dos grupos escolhidos. Não ativa publicação de horas no Kimai. Nomes repetidos não autorizam outros grupos porque a coleta passa a usar identificadores de diálogos, não correspondência aproximada de títulos.

Perfis anteriores com aprovação explícita apenas por nome continuam compatíveis até passarem pelo seletor. Chaves e sessões Telegram ficam restritas ao armazenamento local criptografado ou à sessão privada MadelineProto. A versão PHP segue em **modo de revisão sem publicação de horas**, embora a autenticação e as escolhas locais sejam gravadas normalmente.

Os símbolos de **Nextcloud, Telegram e GitHub** são agora suas marcas, obtidas do projeto Simple Icons (versão 16.37.0), separadas dos pictogramas de interface Lucide. Estão embutidos em public/icons/brands.svg, com licença e observações sobre marcas comerciais nos dois arquivos de licença em public/icons. Não dependem de CDN.

### Isolamento de testes e inicialização do desktop (15/11/2026)

Os testes HTTP que usam Tests\TestCase agora recebem um LocalStore
temporário e exclusivo e um cache de templates Blade igualmente isolado,
injetados antes da primeira requisição. Nunca escreva em
storage/app/private/automatic-kimai/ ou storage/framework/views/
ao executar PHPUnit: esses diretórios pertencem ao runtime real do
aplicativo desktop. Executar PHPUnit como root em um contêiner com a aplicação
montada pode deixar arquivos 0600 pertencentes a root, tornando-os
ilegíveis ao processo da interface (UID normal) e causando HTTP 500.

A suíte de regressão deve preservar o hash, proprietário e permissões de
sources.json no ambiente hospedeiro. Para um diagnóstico somente leitura
do aplicativo desktop instalado, execute
~/.local/bin/automatic-kimai-desktop --check. Esse comando exige resposta
válida do servidor PHP em 127.0.0.1:6817 e a presença do runtime Electron;
não inicia uma janela nem publica horas no Kimai. Em caso de erro HTTP 500,
investigue os logs locais de Laravel e as permissões do armazenamento privado.
Não torne os arquivos públicos (chmod 644) e não execute o servidor desktop
como root para contornar o problema.

### Segurança das credenciais Telegram e dos pacotes distribuídos (15/11/2026)

O ID/hash da API Telegram identificam o **aplicativo**, não a conta conectada.
Um mesmo aplicativo MTProto pode autenticar várias contas separadas, mas
compartilhar livremente o hash permitiria que terceiros utilizassem a identidade
de desenvolvedor da aplicação. O Automatic Kimai não distribui nem embute um
ID/hash pessoais em seu código: a configuração é local e por instalação.

Os valores TELEGRAM_API_ID e TELEGRAM_API_HASH são persistidos no arquivo
storage/app/private/automatic-kimai/secrets.enc, criptografado com a chave
local e protegido pelo sistema de arquivos. A sessão MTProto autenticada é
um **segredo distinto, muito mais sensível**, armazenado sob uma pasta privada
do usuário, nunca publicada no Git. Arquivos .env, sessão, banco SQLite,
cache, logs privados e credenciais têm exclusões de versão e de empacotamento.
A sessão MadelineProto é protegida por permissões do sistema operacional e
não deve ser confundida com o arquivo Laravel criptografado.

No servidor PHP, erros de validação não reutilizam nem gravam telegram_api_hash
ou demais campos de credenciais como dados temporários de formulário.
Os logs Laravel single/daily são criados com modo 0600. O pacote NativePHP
passa por limpeza de chaves e diretórios privados; uma verificação adicional
em nativephp/electron/build/privacy-guard.mjs impede gerar um instalador
quando a aplicação preparada ainda contém chave, tokens, sessões ou logs
persistentes. Essa proteção é testada com dados fictícios no CI, sem copiar
credenciais reais.

Auditoria local: os segredos efetivamente configurados não foram encontrados
nos objetos Git e nos logs inspecionados; isso não substitui uma revisão de
todos os instaladores antigos, backups ou sistemas externos.

**Importante:** a segurança de uma conta Telegram depende da sessão MTProto,
2FA, dispositivos conectados e segurança do computador. Uma eventual
suspeita de comprometimento da sessão deve ser tratada no aplicativo oficial
Telegram, em Configurações - Dispositivos, encerrando a sessão envolvida.

### Catálogo Telegram incremental e sugestões iniciais

A seleção dos grupos deixou de chamar getFullDialogs() antes de mostrar a
tela. O cliente solicita messages.getDialogs em lotes de até 30 diálogos
brutos por consulta e filtra os metadados de grupos/supergrupos localmente,
sem abrir histórico de mensagens. A lista avança por rolagem até o fim ou
pelo botão Carregar mais grupos. O campo de busca filtra apenas os grupos
já carregados, sem anunciar buscas no catálogo inteiro.

Os cursores são criptografados pela chave privada da instalação, limitados a
20 minutos e associados ao identificador da conta. Tentativas de reutilizar
um cursor de outra conta ou repetir o mesmo cursor são recusadas. A seleção
se mantém entre páginas e é validada por IDs exatos ao salvar, sem varrer o
catálogo inteiro novamente.

As comunidades públicas @LibreCodeCoop e @LibreSign são consultadas de forma
pontual na primeira página. Elas só são pré-marcadas se os metadados do
Telegram confirmarem supergrupo e participação da conta, com nome de usuário
público exato. Essa seleção editável não significa consentimento efetivo:
apenas Salvar grupos autorizados e depois Ativar análise dos grupos habilitam
a coleta. Uma lista salva anteriormente nunca recebe novos itens
automaticamente.

Essa paginação foi testada com respostas Telegram simuladas. A velocidade e
a compatibilidade com uma conta real muito grande ainda precisam de
homologação autorizada; nenhum teste desta mudança fez login ou leu mensagens.


### Correção do catálogo de grupos Telegram no aplicativo local (15/11/2026)

MadelineProto 8 normaliza identificadores de chats, diálogos e mensagens para
IDs numéricos do Bot API, incluindo IDs negativos de grupos. Além disso, a
primeira resposta de `messages.getDialogs` pode conter diálogos fixados
**além** dos 30 itens ordinários solicitados. O seletor agora interpreta ambos
os formatos (construtores TL e IDs numéricos já normalizados), tolera uma
quantidade limitada de itens fixados e avança pelo último diálogo não fixado.

A UI mostra a falha na própria lista, habilita **Tentar novamente** e interrompe
a espera após 15 segundos. Ela não permanece em uma mensagem permanente de
carregamento quando o backend retorna HTTP 503.

**Validação local real, somente metadados:** primeira página HTTP 200 em
aproximadamente 0,47 s, 32 diálogos recebidos (incluindo fixados) e 28
grupos válidos; segunda página com mais 16 grupos sem duplicações na seleção.
A inspeção não consultou o conteúdo de mensagens nem habilitou a coleta.
Regressões PHPUnit usam somente identificadores e nomes fictícios.

### Telegram: seleção prioritária e filtro de arquivamento

A tela Telegram abre com **Não arquivados** (messages.getDialogs folder_id=0),
oferece **Arquivados** (folder_id=1) e mantém cursores criptografados
independentes por pasta. A lista é paginada; pesquisa e ordenação por atividade
recente ou nome abrangem **somente os grupos já carregados**. O componente
mostra os itens marcados numa área fixa acima do catálogo, mesmo ao mudar
de pasta, sem perder alterações ainda não salvas. Os grupos arquivados são
identificados visualmente quando seus metadados forem carregados.

Uma conta que participa de @LibreCodeCoop e @LibreSign recebe essas
comunidades **pré-marcadas no formulário**, verificadas por ID e nome de
usuário públicos exatos. Mesmo quando já existiam grupos aprovados num perfil
antigo, as sugestões ficam disponíveis até a **primeira confirmação de uma
seleção nesta interface**. Não há aprovação implícita: é preciso clicar em
**Salvar seleção de grupos** e, em seguida, ativar a coleta separadamente.
Após salvar, o perfil registra que as sugestões foram analisadas, impedindo
que um grupo deliberadamente desmarcado volte a aparecer marcado. É possível
salvar uma seleção vazia, o que suspende a coleta.

Os nomes dos grupos efetivamente aprovados são armazenados numa área local
privada apenas para tornar a lista de selecionados útil sem consultar
individualmente o Telegram a cada visita. Perfis legados sem esses nomes
usam consultas pontuais limitadas e não fazem nova enumeração integral.
O cursor de página contém o identificador da pasta, impedindo seu
reaproveitamento para navegar por outro escopo.
