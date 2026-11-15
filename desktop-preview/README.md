# Automatic Kimai — área de trabalho (Electron, avaliação)

Esta pasta oferece uma **janela desktop Electron real** para o Automatic Kimai PHP em execução no mesmo computador. Não é um atalho que abre uma guia do navegador. O conteúdo da janela é fornecido pelo servidor Laravel local em `http://127.0.0.1:6817/`.

**Limitação importante:** este modo de avaliação não é um instalador independente. Requer o backend PHP local já instalado, atualmente no contêiner `automatic-kimai-php-preview`. Não inclui credenciais pessoais, estado do Kimai, banco SQLite ou uma chave `APP_KEY` dentro do Electron. O empacotamento definitivo NativePHP permanece bloqueado até ser concluído o isolamento da chave por instalação e a homologação dos outros serviços.

## Instalação no Linux/KDE já configurado

A partir da raiz do projeto:

```sh
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
```

O instalador cria a entrada **Automatic Kimai** no menu de aplicativos e, quando existe a pasta `~/Desktop`, o ícone **Automatic-Kimai** na área de trabalho. Ele **não abre janelas** ao instalar. Depois da instalação, abra pelo menu ou dê um duplo clique no ícone, como em qualquer aplicativo desktop.

O inicializador `launch.sh` confere o Electron e o backend PHP no loopback. Se o contêiner PHP existente estiver parado, ele tenta iniciá-lo; não cria novos contêineres, não altera o piloto Python nem registra apontamentos no Kimai. Para checar sem mostrar janela, execute `automatic-kimai-desktop --check`.

O Electron é instalado para desenvolvimento em `nativephp/electron/node_modules/electron`. O arquivo `main.cjs` desativa integração Node no conteúdo web, ativa sandbox e isolamento de contexto, nega permissões e downloads e impede navegação para fora de `http://127.0.0.1:6817`.

### Sandbox do Linux

Em distribuições Ubuntu com bloqueio de namespaces via AppArmor, o Electron precisa de um sandbox de processos corretamente instalado. **Não** utilize `--no-sandbox` para contornar esse bloqueio. Na máquina de avaliação, o sandbox foi verificado em execução isolada com um helper privilegiado já instalado no sistema, mantendo a política global do sistema inalterada. Um instalador oficial deverá fornecer seu próprio mecanismo de sandbox, em vez de assumir a presença de outro aplicativo.

### Portabilidade

`install-desktop.sh` funciona como conveniência da avaliação Linux. Não afirma suporte a macOS ou Windows nem produz binários para terceiros. Veja `../README.md` e `../docs/RELEASE_CHECKLIST.md` para os critérios de distribuição.

### Abrir o Nextcloud para autorizar

Ao selecionar **Conectar pelo navegador**, a interface solicita a autorização
Login Flow v2 e o Electron abre automaticamente o URL HTTPS pendente no
navegador padrão. O processo principal só executa essa ação após a resposta
bem-sucedida do POST local de início, conferindo a URL com o estado atual
no PHP. **Reabrir navegador** é uma alternativa manual e reutiliza a mesma
autorização pendente, sem gerar um segundo token. A janela Electron permanece
restrita ao localhost; nenhum pop-up externo tem acesso ao aplicativo.

Após a autorização e a verificação da identidade, a tela apresenta a conta
e o servidor vinculados; o formulário de conexão fica oculto até a ação
explícita **Trocar conta Nextcloud**. Em navegador web (fora do Electron),
a guia de autorização é reservada no clique inicial para reduzir bloqueios
de pop-up. Caso a abertura automática falhe, o link de reabertura permanece.

Testes de segurança usam um abridor fictício, sem abrir abas reais, e
cobrem tentativas concorrentes, links inválidos, URLs trocadas, falhas de
rede e ausência de abertura quando a requisição de início falha.
