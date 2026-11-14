# Automatic Kimai — guia de uso sem conhecimentos técnicos

**Versão de avaliação · 9/11/2026.** Este guia descreve o que clicar, o que digitar, o que conferir e como reconhecer impedimentos reais. Não existe uma etapa implícita de atendimento por terceiros.

> **Limitação atual do produto:** ainda não existe instalador oficial publicado e testado para Windows, macOS e Linux. O pacote Python `.whl` e o código-fonte **não** são instaladores de duplo clique. Sem um Automatic Kimai previamente instalado, as etapas 3–6 não podem ser executadas. Não execute códigos aleatórios para contornar isso. Esta é uma pendência de distribuição, não uma instrução ausente.

## 1. O que faz e quais informações observa

O Automatic Kimai compara indícios de atividade no computador com os apontamentos existentes no Kimai. Mostra sugestões para revisão e permite registrar atividades presenciais. Não comprova que todo o tempo observado foi trabalho, não transforma uma aba aberta do ChatGPT em horas humanas, não aprova remuneração e não cria faturas.

O **ActivityWatch** armazena informações locais sobre aplicativos e inatividade, e pode manter metadados mais detalhados de janelas e sites do que os relatórios reduzidos do Automatic Kimai. Só habilite as fontes adicionais cujo acompanhamento deseja. Um lançamento autorizado pelo usuário é enviado à conta individual do Kimai.

## 2. Identifique o sistema e instale o ActivityWatch

**Windows:** Iniciar → Configurações → Sistema → Sobre; anote a versão. Entre em https://activitywatch.net/downloads/, escolha **Windows → Installer (recommended)**, abra o instalador da versão estável, siga as telas e deixe marcada **Start ActivityWatch when Windows starts**. Procure **ActivityWatch** no menu Iniciar e abra.

**macOS:** menu  → Sobre Este Mac; identifique a versão e se o chip é Apple ou Intel. Em https://activitywatch.net/downloads/, escolha o arquivo `.dmg` compatível, abra-o, arraste o aplicativo para **Aplicativos** e execute. Confira solicitações de permissões em Configurações do Sistema → Privacidade e Segurança. Se disponível, use **Start at login** no menu do ActivityWatch.

**Linux (Ubuntu, Debian, Mint):** em https://activitywatch.net/downloads/, escolha o arquivo `.deb`, abra na loja de aplicativos do sistema, clique em **Instalar** e depois abra **ActivityWatch** no menu de aplicativos. Outras distribuições podem exigir um método de instalação diferente, que ainda não está documentado para uso sem terminal; não use comandos desconhecidos.

**Teste comum aos três sistemas:** abra http://localhost:5600 no navegador. Deve aparecer a interface do ActivityWatch. Alterne entre duas janelas e verifique se aparece alguma atividade recente. `localhost` significa **este computador**. A extensão opcional do navegador pode acrescentar informações sobre sites, mas não é exigida para o primeiro teste. Manual oficial: https://docs.activitywatch.net/en/latest/getting-started.html.

## 3. Crie um token pessoal no Kimai

1. Abra **o endereço HTTPS da sua instância do Kimai** (por exemplo, `https://kimai.exemplo.org`). O domínio do exemplo não é um servidor disponível. Entre na sua própria conta, nunca na de outra pessoa.
2. No menu do seu usuário (normalmente no alto à direita), abra **Acesso à API / API access**.
3. Clique em **Criar / Create**. Dê o nome `Automatic Kimai - meu computador` e selecione uma validade compatível com a avaliação, quando houver essa opção.
4. **Copie o token imediatamente.** Ele é diferente da senha de acesso e aparece uma única vez. Não envie por e-mail, mensagem ou captura de tela.
5. Se fechou a tela antes de copiar, exclua o token que não pode mais recuperar e gere um novo. Caso não exista opção **Acesso à API**, sua conta ainda não dispõe dessa função/permissão; não tente contornar usando credenciais alheias.

Documentação oficial: https://www.kimai.org/documentation/user-api.html.

## 4. Primeira configuração do Automatic Kimai

Esta etapa exige o aplicativo **já instalado** no computador.

1. Abra **Automatic Kimai — Configuração** no menu de aplicativos e selecione a aba **1. Kimai**.
2. **Identificação local:** mantenha `user` se você utiliza apenas um perfil neste computador. Essa identificação não é a senha do Kimai.
3. **Fuso horário (IANA):** normalmente é preenchido a partir do sistema operacional. Confira se é **o mesmo** mostrado nas preferências da sua conta Kimai; por exemplo, `Europe/Paris` ou `America/Sao_Paulo`. Se forem diferentes, ajuste este campo antes de continuar.
4. **Endereço HTTPS do Kimai:** informe o mesmo endereço que você usa para acessar a sua instância, sem barra final. Exemplo ilustrativo: `https://kimai.exemplo.org`. **Token pessoal:** cole o código criado na etapa 3.
5. Clique em **Testar conexão Kimai**. Deve aparecer confirmação de que seu usuário e os projetos foram reconhecidos.
6. Clique em **Escolher projeto e atividade pelo nome**. Selecione **o projeto interno correto**, escolha a atividade correspondente e confirme em **Utilizar esta seleção**. Os campos de números são preenchidos automaticamente. Se não aparecer projeto interno adequado, **não escolha um cliente aleatório**: esta conta não está pronta para esse vínculo.
7. Na aba **2. Fontes de dados**, mantenha **ActivityWatch** ativado. GitHub, Nextcloud, Telegram e outras fontes não são necessárias ao primeiro teste. Confira **ChatGPT** e **Claude** e desmarque as opções caso não queira que o contexto dessas abas participe da análise.
8. Na aba **3. Automação**, deixe **Autorizar apontamentos internos PROVISÓRIOS automáticos** **desmarcado**. Para registrar manualmente depois de conferir, marque **Permitir registro MANUAL de horas após confirmação individual**.
9. Clique em **Salvar configurações** e depois em **Instalar sincronização 30 min**. Esse botão instala a rotina periódica de revisão, não autoriza escrita automática.
10. Clique em **Atualizar análise sem escrever no Kimai** e abra **Abrir relatório de revisão**. O primeiro relatório pode ter poucos dados; ele não cria histórico anterior ao início do ActivityWatch.

**Configuração concluída** significa: conexão ao Kimai confirmada, perfil salvo, agendamento instalado e relatório acessível. Qualquer item não alcançado precisa de uma correção específica; consulte a seção de erros.

## 5. Revise as horas do dia

1. Abra **Automatic Kimai → 3. Automação → Abrir relatório de revisão**.
2. Compare as sugestões com os horários em que **você realmente trabalhou**, descontando almoço, pausas pessoais e execução autônoma da IA.
3. Clique em **Revisar horas de trabalho não registradas**. Selecione uma sugestão; a data e os horários aparecerão no formulário, mas podem ser corrigidos.
4. Escreva **Dia (AAAA-MM-DD)**, por exemplo `2026-11-09`, **Início real** e **Fim real** no formato `HH:MM`, por exemplo `09:00` e `11:30`.
5. Em **Atividade realizada**, escreva uma descrição curta, como `Revisão de documentos`. Evite detalhes confidenciais.
6. Se fez intervalo para almoço, use **duas entradas**, uma antes e outra depois. Exemplo: 09h–12h e 13h–17h. Não conte 12h–13h como trabalho.
7. Marque **Confirmo que TRABALHEI pessoalmente nesse período** somente se for verdade. Confira o resumo e clique em **Confirmar e registrar no Kimai**. Se a sugestão não corresponde a trabalho, simplesmente não a confirme.

O registro manual depende da opção de registro manual ativada na aba **Automação**. A interface impede duplicação e sobreposição com apontamentos existentes.

## 6. Registre trabalho presencial ou fora do computador

1. Na aba **3. Automação**, clique em **Registrar trabalho presencial / fora do computador**.
2. Digite a **data** em `AAAA-MM-DD` (ex.: `2026-11-06`) e uma **Atividade** curta (ex.: `Reunião de projeto`).
3. Informe **Início da jornada** e **Fim da jornada**, como `15:00` e `17:00`.
4. No campo **Pausas**, pode haver um exemplo preenchido `12:00-13:00`. **Apague-o se não houve pausa.** Caso tenha feito pausa, escreva `12:00-13:00`; para duas pausas, separe por vírgula (`12:00-13:00,15:00-15:15`).
5. Em **Origem dos horários**, escreva `Horários reais informados` ou `Agenda do compromisso`, conforme o caso.
6. Marque **Confirmo que participei e TRABALHEI neste compromisso** apenas se ocorreu. Marque **Confirmei também os horários exatos** apenas se conhece os horários reais.
7. Clique em **Conferir e registrar presença**, examine os períodos mostrados e confirme.

**Virada da meia-noite:** o processamento automático trata o limite de 00h quando existe evidência suficiente. O formulário manual atual, porém, ainda não aceita uma entrada única que comece num dia e termine no próximo. Não digite horas ou datas falsas para contornar essa restrição.

## 7. Erros e decisões

| Situação | O que fazer |
|---|---|
| **Automatic Kimai não está instalado.** | Não há instalador geral liberado. O `.whl` não é aplicativo de duplo clique. Não há procedimento autônomo de instalação nesta versão. |
| **Kimai abre, mas o teste de conexão falha.** | Confira endereço, token, validade do token e fuso nas preferências do Kimai. Quando preciso, crie novo token, cole-o e repita **Testar conexão Kimai**. |
| **Não há Acesso à API no perfil.** | A conta ainda não possui a opção necessária. Não use senha comum ou token de outra pessoa. |
| **Não aparece projeto interno.** | Não escolha cliente ou projeto inadequado. A conta não possui um vínculo utilizável no momento. |
| **http://localhost:5600 não abre.** | Abra ActivityWatch no menu de aplicativos; confirme que está em execução e tente novamente. |
| **Relatório vazio.** | Veja se há dados na interface local do ActivityWatch. No Automatic Kimai, use **Atualizar análise sem escrever no Kimai** e depois abra o relatório. |
| **Sugestão corresponde a tempo pessoal.** | Não confirme a sugestão: o sistema só observa indícios. |
| **Um registro já existente está errado.** | Não crie outro por cima. Confira seu ID no site Kimai; o formulário de revisão não edita todos os registros anteriores. |
| **O envio apresentou resultado incerto.** | Não clique outra vez em registrar. Confira primeiro no Kimai se houve criação do apontamento; enviar novamente pode duplicar. |
| **O sistema alerta para aplicativo não verificado.** | Não ignore o aviso. Os executáveis do Automatic Kimai ainda não foram oficialmente homologados e assinados. |

## 8. Dicionário rápido

**ActivityWatch** = programa local que acompanha aplicativos e inatividade. **Kimai** = sistema em que ficam os horários de trabalho. **Token** = credencial pessoal para um aplicativo acessar sua conta. **Apontamento** = um período de horas registrado. **Revisão** = sugestão que ainda precisa de confirmação. **Não faturável** = não é uma cobrança ao cliente nem uma aprovação de remuneração.

## 9. Limitações reais antes da distribuição livre

Um guia claro não substitui funções ausentes. Ainda faltam: **instaladores oficiais** por sistema, instalação e diagnóstico guiados do ActivityWatch, menos campos técnicos no primeiro acesso, botão simples para interromper/remover a rotina, testes reais em Windows e macOS e roteiro de atualização/desinstalação. Até esses pontos existirem, **não é correto anunciar o aplicativo como instalação autônoma para leigos**. O texto não manda procurar outra pessoa para contornar uma pendência que é responsabilidade do software.
