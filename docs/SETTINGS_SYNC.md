# Sincronização criptografada de configurações

O Automatic Kimai pode usar o **Files/WebDAV do Nextcloud** como armazenamento de
backup pessoal. Não depende de aplicativo extra no servidor. O recurso é
**desativado por padrão** e exige autorização por dispositivo.

## Primeiro dispositivo

1. Conecte seu próprio Nextcloud pelo Login Flow v2, sem reutilizar credenciais
   do cliente de sincronização nem a senha principal.
2. Abra **Preferências → Backup e sincronização**.
3. Crie uma senha de recuperação exclusiva com pelo menos 16 caracteres.
4. Escolha explicitamente se deseja exportar tokens individuais do Kimai e
   GitHub e os identificadores da API Telegram (criptografados).
5. Ative o recurso. O sistema cria um único arquivo criptografado em
   **Arquivos → Automatic Kimai**, sob a conta Nextcloud autenticada.

A senha de recuperação não é gravada. A chave derivada é guardada **somente
no dispositivo**, protegida pela chave da aplicação local. O arquivo do Nextcloud é
cifrado com Argon2id (64 MiB, três operações) e
XChaCha20-Poly1305, com nonce novo em cada versão e identidade Nextcloud
como AAD (dados autenticados).

## Segundo dispositivo

1. Instale o aplicativo e autorize **a mesma conta Nextcloud** neste dispositivo.
2. Ative o backup com a **mesma senha de recuperação** e a **mesma opção de
   credenciais** escolhida na criação.
3. Se não houver configuração local, a restauração é automática. Se já houver,
   o programa para e pergunta qual configuração deve prevalecer.
4. O Kimai e o GitHub podem recuperar seus tokens **apenas se o usuário
   explicitamente optou por compartilhá-los**. Cada integração deve ser
   revalidada caso o provedor revogue ou restrinja o token.
5. A sessão **Telegram não é copiável** com segurança: autorize-a
   separadamente (QR/2FA) no segundo dispositivo. Lista de grupos/consentimento
   para leitura também exige aprovação local.

## Proteção contra perda de dados

- Somente configurações portáteis são transferidas. Não são enviados SQLite,
  logs, arquivos de trabalho, dados de ActivityWatch, documentos, sessões
  MadelineProto, cookies, senha do Nextcloud ou a chave da aplicação Laravel.
- Permissões de publicação automática no Kimai **nunca são restauradas**:
  o perfil importado fica em modo de revisão.
- Alterações concorrentes geram um **conflito**, sem substituição automática.
  O usuário precisa decidir explicitamente qual versão prevalece.
- Gravações WebDAV usam If-Match com ETag forte, ou If-None-Match: *
  na criação. Uma falha ou alteração concorrente cancela a escrita.
- A sincronização ocorre pelo scheduler a cada cinco minutos enquanto o
  aplicativo estiver em execução. O botão **Sincronizar agora** antecipa o ciclo.
- Desconectar a conta Nextcloud apaga apenas a chave de sincronização local;
  o backup cifrado permanece no armazenamento remoto.
- Se a senha for perdida, a aplicação não consegue descriptografar o backup.
  Isso é intencional. A revogação de credenciais precisa ser feita no provedor.

Não compartilhe a pasta com terceiros, mesmo que seu conteúdo seja cifrado.
Nunca armazene a senha de recuperação junto ao arquivo de backup.

## Revisão técnica

Camadas independentes:
- SettingsSnapshot: allowlist para dados portáteis;
- SettingsVault: cifra autenticada e derivação de chave;
- NextcloudSettingsDav: WebDAV autenticado, HTTPS e ETag;
- SettingsSyncService: comparação de alterações e conflito de escrita;
- SettingsSyncController: ações locais com CSRF, validação e opt-in;
- comando agendado automatic-kimai:settings-sync.

Os testes de unidade utilizam transporte WebDAV fictício. Não devem escrever
em arquivos ou contas Nextcloud reais da pessoa desenvolvedora.
