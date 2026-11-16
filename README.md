# Automatic Kimai

**Seu trabalho acontece em várias ferramentas. Seu controle de horas pode ser muito mais simples.**

O Automatic Kimai reúne sinais das atividades do dia, compara períodos com o
que já está registrado no [Kimai](https://www.kimai.org/) e ajuda a identificar
horas não conciliadas, pausas e informações que merecem atenção.

Mais contexto para a gestão do tempo, menos trabalho para reconstruir a
jornada no fim do dia.

## O que você ganha

- **Visão mais completa do tempo:** reúna informações do computador,
  compromissos e ferramentas de trabalho em um só lugar.
- **Menos lançamentos duplicados:** compare automaticamente os períodos
  encontrados com o histórico da sua conta Kimai.
- **Conciliação com contexto:** identifique lacunas e diferencie atividades
  confirmadas de observações que ainda não comprovam trabalho.
- **Trabalho fora do computador:** informe reuniões, atendimentos e atividades
  presenciais, incluindo horários e pausas.
- **Controle e privacidade:** conecte apenas as fontes que desejar, utilizando
  suas próprias contas e autorizações individuais.

## Integrações

<text>ActivityWatch</text> para atividade e inatividade local; <text>Nextcloud</text>
para agenda e colaboração; <text>GitHub</text> para contexto de desenvolvimento;
e <text>Telegram</text> para grupos explicitamente autorizados. A conexão com
o Kimai mantém seus clientes, projetos e atividades disponíveis para conciliação.

O Automatic Kimai não pressupõe uma empresa ou instância específica. Cada
pessoa utiliza sua própria conta e decide quais fontes serão analisadas.

## Download

Encontre os instaladores e as notas de cada versão em
**[Baixar a versão mais recente](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest)**.

| Sistema operacional | Formato | Como instalar |
| --- | --- | --- |
| **Linux** | AppImage | Baixe o arquivo `.AppImage`, conceda permissão de execução e abra |
| **Windows** | `.exe` | Baixe o instalador e siga as instruções do assistente |
| **macOS** | `.dmg` | Baixe a versão compatível com seu Mac (Intel ou Apple Silicon) e mova para Aplicativos |

Leia as notas da versão antes da instalação, especialmente as informações
sobre assinatura e compatibilidade. A instalação não deve exigir que você
configure PHP, Composer ou Node.js manualmente.

## Comece em três etapas

1. **Conecte o Kimai.** Informe a URL da sua instância e autentique sua conta.
2. **Escolha as fontes.** Autorize individualmente as integrações que contribuem
   para seu contexto de trabalho.
3. **Confira a conciliação.** Veja os registros existentes, as lacunas e os
   períodos que precisam de informação adicional.

Eventos de agenda, mensagens ou tempo de tela **não são, isoladamente, prova de
horas trabalhadas**. O Automatic Kimai preserva essa distinção para evitar
conclusões e lançamentos indevidos.

## Privacidade

A análise utiliza armazenamento local e autorizações individuais. As
integrações não são ativadas automaticamente e dados privados não precisam
ser enviados a um serviço de análise centralizado. Revogue credenciais nas
contas de origem quando desejar encerrar um acesso.

Conheça a [política de privacidade](PRIVACY.md) e as
[orientações de segurança](SECURITY.md).

## Comunidade e documentação

O Automatic Kimai é software livre sob a licença
[GPL-3.0-or-later](LICENSE). Problemas e sugestões podem ser registrados
nas [issues](https://github.com/LibreCodeCoop/automatic-kimai/issues).

[Guia de uso](docs/GETTING_STARTED.md) ·
[Documentação técnica](docs/DEVELOPMENT.md) ·
[Contribuir](CONTRIBUTING.md)
