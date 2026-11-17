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

Escolha seu sistema operacional para baixar diretamente o instalador da
**[versão estável mais recente](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest)**.
Os links são permanentes: continuam apontando para a última versão sem
precisar atualizar este README a cada lançamento.

| Download | Instalação |
| --- | --- |
| [![Baixar para Linux — AppImage](https://img.shields.io/badge/Linux-AppImage-333333?style=for-the-badge&logo=linux&logoColor=white)](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest/download/Automatic-Kimai-linux-x86_64.AppImage) | Conceda permissão de execução ao `.AppImage` e abra |
| [![Baixar para Windows — instalador](https://img.shields.io/badge/Windows-Instalador-0078D4?style=for-the-badge&logo=windows11&logoColor=white)](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest/download/Automatic-Kimai-windows-x64-setup.exe) | Execute o `.exe` e siga as instruções |
| [![Baixar para macOS — Apple Silicon](https://img.shields.io/badge/macOS-Apple%20Silicon-555555?style=for-the-badge&logo=apple&logoColor=white)](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest/download/Automatic-Kimai-macos-arm64.dmg) | Abra o `.dmg` e mova o aplicativo para Aplicativos |
| [![Baixar para macOS — Intel](https://img.shields.io/badge/macOS-Intel-555555?style=for-the-badge&logo=apple&logoColor=white)](https://github.com/LibreCodeCoop/automatic-kimai/releases/latest/download/Automatic-Kimai-macos-x64.dmg) | Abra o `.dmg` e mova o aplicativo para Aplicativos |

Para outras versões e notas de lançamento, consulte
**[GitHub Releases](https://github.com/LibreCodeCoop/automatic-kimai/releases)**.
Leia as notas da versão antes de instalar: a disponibilidade de assinatura
digital e notarização depende da plataforma e da release. Não é necessário
instalar PHP, Composer ou Node.js.


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
