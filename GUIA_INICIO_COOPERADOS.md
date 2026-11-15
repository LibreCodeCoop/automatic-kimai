# Automatic Kimai — guia para usuários da versão PHP

**Avaliação técnica, 15/11/2026.** O Automatic Kimai em PHP já dispõe de
interface local, mas **não existe instalador final homologado** para pessoas
sem conhecimento técnico em Windows, macOS e Linux. O programa precisa estar
previamente preparado no computador. Não utilize instaladores não oficiais
nem desative uma solução anterior que já registre horas.

## 1. O que o programa faz

O Automatic Kimai compara evidências de uso do computador com os horários
existentes na sua conta Kimai e oferece **propostas para revisão**. Um
compromisso na agenda, uma chamada aparente, uma conversa ou um agente de IA
executando não prova que você trabalhou durante todo aquele período.
Pausas e tempo pessoal precisam ser descontados.

O aplicativo lê somente as fontes que forem habilitadas. Os dados sensíveis
ficam restritos ao perfil local. A revisão e a aprovação no PHP **ainda não
representam lançamento remoto**, pagamento aprovado ou faturamento.

## 2. Antes do primeiro acesso

1. Instale o [ActivityWatch](https://activitywatch.net/downloads/) apropriado
   ao seu sistema operacional e confirme que http://localhost:5600 abre no
   mesmo computador. A extensão do navegador é opcional.
2. Acesse a **sua própria instância Kimai** por HTTPS. No perfil de usuário,
   gere um token individual de API, se sua conta permitir. Não compartilhe
   esse token por mensagem, e-mail ou captura de tela.
3. Abra o **Automatic Kimai PHP** já preparado no computador. Na avaliação
   Linux, ele pode aparecer no menu de aplicativos como Automatic Kimai;
   o backend local também pode ser acessado em http://127.0.0.1:6817.
   Esses endereços só funcionam na própria máquina onde o programa está
   instalado.

## 3. Conecte o Kimai

Na opção **Conta Kimai**, informe o endereço HTTPS da sua instância, o token
individual e o fuso horário correspondente ao perfil Kimai. Selecione
**cliente, projeto e atividade** usando os dados apresentados no catálogo.
Não escolha outro cliente para contornar a falta do seu projeto.

Salve e confirme a conexão. Se o servidor ou o token não responderem,
verifique a própria conta e sua autorização de acesso à API.

## 4. Escolha as fontes conscientemente

Em **Fontes de dados**, mantenha ActivityWatch para análise do computador.
Nextcloud, GitHub e Telegram são opcionais.

- Nextcloud: autorize usando Login Flow v2 no próprio navegador ou uma
  senha de aplicativo individual e revogável. Marque separadamente Agenda,
  Talk, Activity e Mail conforme suas necessidades.
- GitHub: use seu token individual; a aplicação conserva apenas
  metadados selecionados e não infere duração humana de um evento GitHub.
- Telegram: abrir a tela de configuração **não** autoriza leitura de
  mensagens. Conecte sua conta via QR/2FA local, escolha os grupos
  profissionais e, separadamente, ative a coleta. Os metadados de áudio
  PipeWire dependem dessa ativação e não gravam som.
- ChatGPT/Claude no navegador: o contexto de abas é apenas um sinal,
  não o conteúdo das conversas nem prova de dedicação exclusiva.

Ao desligar uma fonte, novas consultas dela são interrompidas. Isso não
revoga automaticamente tokens no serviço externo; revogue-os no serviço
caso não sejam mais necessários.

## 5. Analise e revise os períodos

Abra **Visão geral → Atualizar análise**, depois **Revisar minhas horas**.

A fila distingue propostas que precisam de decisão, períodos já cobertos
por lançamentos, conflitos e estados incertos. Cada proposta contém início,
fim e evidências observadas, sem comprovar por si só horas de trabalho.

Para uma proposta elegível, confira começo, término, pausas, **projeto,
atividade e descrição concreta**. Marque as duas
confirmações somente depois de verificar pessoalmente o trabalho realizado
e clique em **Aprovar somente na fila local**. Caso a hipótese seja pessoal,
incorreta ou não seja trabalho, use **Rejeitar esta proposta**.

Uma mudança nas evidências exige nova aprovação. Uma gravação remota de
resultado incerto não deve ser repetida; primeiro é necessário reconciliar
o Kimai por consulta GET.

**Atenção:** nesta migração a aprovação altera a fila local, mas não publica
horas no servidor. O publicador protegido requer homologação e autorização
independentes depois de interromper o escritor antigo.

## 6. Trabalho presencial ou fora do computador

Use **Trabalho presencial** para informar data, atividade, horário real,
pausas e fonte de confirmação. Não trate um evento na agenda como presença
comprovada. O formulário registra os períodos no Kimai **apenas após** a conclusão do
corte controlado do escritor PHP. Enquanto o runtime Python estiver ativo,
a publicação pelo PHP permanece bloqueada para evitar duplicações.

Se o trabalho cruzar a meia-noite, revise os intervalos de cada data.
Não altere artificialmente horários nem deixe uma pausa computada como
trabalho para forçar um formulário.

## 7. Problemas frequentes

| Situação | Orientação |
| --- | --- |
| Automatic Kimai não está instalado | Não existe instalador final para o público; a avaliação depende de uma configuração técnica validada |
| Kimai não conecta | Verifique URL HTTPS, token individual, fuso horário e permissão de API |
| Cliente, projeto ou atividade ausente | Não atribua a horas a outro contrato; ajuste a autorização/catálogo |
| ActivityWatch não fornece dados | Verifique http://localhost:5600 e os observadores de janela e inatividade |
| Análise tem um período muito longo | Considere AFK, trabalho não observado, pausas, agente de IA ou agenda sem presença |
| Uma sugestão corresponde a tempo pessoal | Rejeite; não registre como trabalho |
| Operação remota tem resultado incerto | Nunca clique novamente para reenviar; concilie primeiro |
| Telegram não foi ativado | Autorize conta e grupos explicitamente; configuração sozinha não autoriza o scanner |
| Instalação em Windows/macOS | A distribuição NativePHP específica ainda precisa de testes reais e assinatura apropriada |

## 8. Limites antes da distribuição

Ainda faltam homologação do ciclo completo de publicação em Kimai descartável,
coleta Telegram com consentimento, emissão de telemetria de agentes,
agendamento definitivo de componentes PHP, instaladores seguros e testes
Windows/macOS. Não se deve apresentar a migração como concluída até
comprovar essas etapas.

Documentação técnica e estado verificado:
[php-app/README.md](php-app/README.md),
[PHP_MIGRATION.md](PHP_MIGRATION.md) e
[RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).
