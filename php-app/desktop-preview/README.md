# Automatic Kimai — área de trabalho (Electron, avaliação)

Esta pasta oferece uma **janela desktop Electron real** para o Automatic Kimai PHP em execução no mesmo computador. Não é um atalho que abre uma guia do navegador. O conteúdo da janela é fornecido pelo servidor Laravel local em `http://127.0.0.1:6817/`.

**Limitação importante:** este modo de avaliação não é um instalador independente. Requer o backend PHP local já instalado, atualmente no contêiner `automatic-kimai-php-preview`. Não inclui credenciais pessoais, estado do Kimai, banco SQLite ou uma chave `APP_KEY` dentro do Electron. O empacotamento definitivo NativePHP permanece bloqueado até ser concluído o isolamento da chave por instalação e a homologação dos outros serviços.

## Instalação no Linux/KDE já configurado

A partir de `php-app`:

```sh
./desktop-preview/launch.sh --check
./desktop-preview/install-desktop.sh
```

O instalador cria a entrada **Automatic Kimai** no menu de aplicativos e, quando existe a pasta `~/Desktop`, o ícone **Automatic-Kimai** na área de trabalho. Ele **não abre janelas** ao instalar. Depois da instalação, abra pelo menu ou dê um duplo clique no ícone, como em qualquer aplicativo desktop.

O inicializador `launch.sh` confere o Electron e o backend PHP no loopback. Se o contêiner PHP existente estiver parado, ele tenta iniciá-lo; não cria novos contêineres, não altera o piloto Python nem registra apontamentos no Kimai. Para checar sem mostrar janela, execute `automatic-kimai-desktop --check`.

O Electron é instalado para desenvolvimento em `php-app/nativephp/electron/node_modules/electron`. O arquivo `main.cjs` desativa integração Node no conteúdo web, ativa sandbox e isolamento de contexto, nega permissões e downloads e impede navegação para fora de `http://127.0.0.1:6817`.

### Sandbox do Linux

Em distribuições Ubuntu com bloqueio de namespaces via AppArmor, o Electron precisa de um sandbox de processos corretamente instalado. **Não** utilize `--no-sandbox` para contornar esse bloqueio. Na máquina de avaliação, o sandbox foi verificado em execução isolada com um helper privilegiado já instalado no sistema, mantendo a política global do sistema inalterada. Um instalador oficial deverá fornecer seu próprio mecanismo de sandbox, em vez de assumir a presença de outro aplicativo.

### Portabilidade

`install-desktop.sh` funciona como conveniência da avaliação Linux. Não afirma suporte a macOS ou Windows nem produz binários para terceiros. Veja `../README.md` e `../../PHP_MIGRATION.md` para o estado real da migração.
