# Guia de início — Automatic Kimai

O Automatic Kimai compara sinais de atividade com registros existentes na sua
conta Kimai. Ele **não** transforma automaticamente todo o tempo com uma janela
aberta ou compromisso na agenda em horas trabalhadas.

Este guia serve a qualquer pessoa ou organização que utilize Kimai.
O código está em fase alpha: ainda não existe um instalador final homologado
para todos os sistemas operacionais.

## Antes de começar

1. Prepare o aplicativo localmente conforme as instruções de
   [desenvolvimento](DEVELOPMENT.md), ou utilize uma instalação distribuída
   e validada pela pessoa responsável pelo seu ambiente.
2. Caso queira analisar atividade de tela, instale o
   [ActivityWatch](https://activitywatch.net/downloads/) e confirme seu serviço
   no próprio computador em http://127.0.0.1:5600.
3. Acesse a sua instância Kimai por HTTPS e gere um token individual de API.
   Não compartilhe tokens, senhas, logs privados ou capturas com credenciais.

## Conectar sua conta Kimai

Na tela **Conta Kimai**, informe a URL HTTPS, o token e o fuso horário.
Escolha cliente, projeto e atividade usando o catálogo retornado pela sua
própria instância. Salve e confirme a conexão.

## Autorizar fontes de dados

Em **Fontes de dados**, cada integração é opcional e precisa de autorização
separada:

- **ActivityWatch:** sinais de janela e inatividade do próprio computador;
  dados de uma janela não comprovam a duração do trabalho.
- **Nextcloud:** utilize autorização no navegador (Login Flow v2) ou uma
  senha de aplicativo individual. Selecione Agenda, Talk, Activity e Mail
  separadamente.
- **GitHub:** autentique sua conta individual. Eventos de código são marcadores
  de contexto, não medidores contínuos de trabalho.
- **Telegram:** faça login por QR e 2FA quando necessário. Escolha
  explicitamente os grupos autorizados para consulta; habilitar um grupo para
  coleta não o classifica automaticamente como contexto profissional.

Ao desativar uma fonte, novas consultas deixam de ocorrer. Revogue
credenciais diretamente no serviço de origem se não forem mais necessárias.

## Acompanhar suas horas

Na **Visão geral**, execute **Atualizar análise**. Na tela **Calendário semanal** (prova de conceito somente leitura), navegue entre semanas para visualizar os horários confirmados no Kimai ao lado das observações locais; blocos de agenda e atividade observada não são somados às horas registradas. Utilize **Atualizar do Kimai** para consultar novamente os lançamentos da semana e selecione blocos, inclusive os curtos, para visualizar seus horários e detalhes. Abra **Conciliação de horas**
para ver três situações distintas:

- **Já registrado:** o Kimai possui um lançamento correspondente; a
  ferramenta evita criá-lo novamente.
- **Atividade não confirmada:** existem sinais de atividade sem duração humana
  comprovada. Essas observações permanecem no histórico para reavaliação,
  sem publicação de horas fictícias.
- **Pendência que exige atenção:** uma situação efetivamente bloqueada ou
  uma aprovação que tenha perdido validade pode exigir tratamento específico.

Uma fila de revisão vazia não garante que toda a jornada foi registrada.
Para trabalho comprovado fora do computador, utilize **Informar horas**, com data, horários reais e descrição. Se houver pausa, abra **Informar pausa (opcional)** para descontá-la. A publicação em servidor
depende da política autorizada para a instalação.

## Problemas comuns

| Sintoma | Verificação |
| --- | --- |
| Kimai não conecta | URL, token, fuso horário e permissões da API |
| Nenhum dado do computador | Serviço ActivityWatch e consentimento da fonte |
| Telegram não coleta | Conta conectada, grupos selecionados e ativação explícita |
| Período grande ou incerto | AFK, pausas, lacunas e fontes de contexto incompletas |
| Horas ainda não publicadas | O publicador pode estar em modo somente leitura |
| Resultado remoto incerto | Consulte o Kimai; não tente repetir POST/PATCH manualmente |

Veja [Homologação](HOMOLOGATION.md) e [Checklist de release](RELEASE_CHECKLIST.md)
para requisitos de instalação e distribuição. A versão de avaliação local não
deve substituir outro publicador sem um corte operacional verificado.
