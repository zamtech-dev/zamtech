# Passo a passo: migrar o blog pro WordPress (sem perder velocidade)

Isso aqui é o roteiro pra trocar o blog caseiro pelo WordPress, do jeito que
combinamos: o WordPress fica escondido, só você acessa pra escrever. Quem
visita o site nunca encosta nele — o Astro busca o conteúdo de lá e gera
página estática, do mesmo jeito rápido que o resto do zamtech.com.br já é.

Se algum passo aqui não bater com o que você está vendo na tela (o cPanel e o
WordPress mudam de aparência de vez em quando), me manda um print que eu
ajusto a instrução.

## 1. Criar a pasta (nada de subdomínio)

Mudança em relação ao plano original: como o DNS do zamtech.com.br não é
gerenciado pela Hostgator (é de terceiros que você prefere não acionar), um
subdomínio como `cms.zamtech.com.br` não ia funcionar — ele precisa de um
registro de DNS novo, e só quem administra o DNS pode criar isso.

A solução é instalar o WordPress numa **pasta dentro do domínio principal**
em vez de um subdomínio: `zamtech.com.br/cms`. Uma pasta não precisa de DNS
nenhum — o domínio já existe e já funciona, é só mais um caminho dentro
dele. Pra quem visita, a diferença não existe (ele nunca vê essa URL de
qualquer forma).

Não precisa criar a pasta manualmente — o instalador do WordPress cria ela
sozinho no próximo passo.

## 2. Instalar o WordPress

1. No cPanel, procure por **"Softaculous Apps Installer"** (ou
   "WordPress" direto, geralmente aparece um ícone dele na tela inicial).
2. Clique em instalar, e quando pedir "Em qual domínio/pasta":
   - Domínio: `zamtech.com.br` (o principal, não um subdomínio)
   - Pasta (Directory/In Directory): `cms`
3. Escolha um usuário e senha de admin fortes (não use o mesmo do painel do
   blog antigo). Guarda isso num gerenciador de senhas.
4. Nome do site pode ser qualquer coisa, tipo "Zamtech CMS" — ninguém vê
   isso além de você.
5. Termina a instalação e confirma que consegue entrar em
   `zamtech.com.br/cms/wp-admin`.

Se um dia o DNS desse domínio passar a ser gerenciado por vocês (ou você
conseguir pedir esse registro à empresa terceirizada), dá pra migrar pra um
subdomínio depois — é só trocar um endereço no código, me avisa quando
chegar essa hora.

## 3. Esconder do Google

1. Dentro do WordPress: **Configurações > Leitura**.
2. Marque a caixinha **"Desencorajar mecanismos de busca de indexar este
   site"**.
3. Salvar.

## 4. Instalar o ACF (pra ter os campos de SEO)

1. No WordPress: **Plugins > Adicionar novo**.
2. Busque por **"Advanced Custom Fields"** (o plugin gratuito, da WP Engine).
3. Instalar e ativar.
4. Menu novo vai aparecer: **Campos personalizados**. Clique em
   **"Adicionar novo"** grupo de campos.
5. Nome do grupo: `SEO do artigo`.
6. Adicione dois campos:
   - **Rótulo:** `Meta título` — **Nome do campo:** `meta_titulo` — **Tipo:** Texto
   - **Rótulo:** `Meta descrição` — **Nome do campo:** `meta_descricao` — **Tipo:** Área de texto
7. Em **Regras de localização**, deixe "Tipo de post é igual a Post".
8. Desça até a aba de configurações do grupo e procure uma opção chamada
   **"Mostrar na API REST"** (ou "Show in REST API") — deixe **ativada**.
   - Se essa opção não existir na sua versão do ACF (às vezes muda de
     lugar), me avisa e me manda um print — eu escrevo um pequeno código
     alternativo que resolve igual, sem precisar de ACF PRO.
9. Salvar.

Esses dois campos ficam vazios por padrão — se você não preencher, o site
usa automaticamente o título do artigo e o resumo dele, do mesmo jeito que o
sistema antigo fazia.

## 5. Criar as categorias

Em **Posts > Categorias**, crie as categorias que você quer usar (ex:
Residencial, Empresarial, Dicas, Novidades — ou outras, fica a seu critério
agora).

## 6. Gerar o token do GitHub

Esse token é o que deixa o WordPress avisar o GitHub "acabei de publicar
algo, pode reconstruir o site".

1. No GitHub, vá em **Settings** (da sua conta, não do repositório) >
   **Developer settings** > **Personal access tokens** > **Fine-grained
   tokens**.
2. **Generate new token**.
3. Em "Repository access", escolha **"Only select repositories"** e marque
   só o repositório do zamtech.
4. Em "Permissions", dê:
   - **Actions:** Read and write
   - **Contents:** Read and write
5. Gera o token e **copia na hora** (o GitHub só mostra uma vez).

## 7. Colar o token no WordPress

1. Entre no arquivo `wp-config.php` do WordPress (via cPanel > Gerenciador
   de Arquivos, dentro da pasta `cms` que o instalador criou em
   `public_html`).
2. Procure a linha que diz `/* That's all, stop editing! Happy
   publishing. */` e cole **antes** dela:

   ```php
   define('ZAMTECH_GITHUB_TOKEN', 'cole_o_token_aqui');
   define('ZAMTECH_GITHUB_REPO', 'seu-usuario/nome-do-repositorio');
   ```

   (o nome do repositório é a parte da URL do GitHub depois de
   `github.com/`, tipo `lidson/zamtech`)

## 8. Instalar o aviso automático

1. Ainda no Gerenciador de Arquivos, dentro da pasta `cms`, entre em
   `wp-content`.
2. Se não existir, crie uma pasta chamada `mu-plugins`.
3. Cole dentro dela o arquivo `wp-mu-plugin-avisar-github.php` que eu te
   mandei (é só colar — "mu-plugin" ativa sozinho, não precisa ir em
   Plugins > Ativar).

## 9. Testar tudo

1. No seu computador, antes de dar `git push` (porque o push já dispara o
   deploy pro site no ar): rode `npm run build` na pasta do projeto e
   confere se termina sem erro. Se der erro, me manda o print de tudo que
   apareceu no terminal.
2. Publique um artigo de teste no WordPress
   (`zamtech.com.br/cms/wp-admin`).
3. Confira na aba **Actions** do seu repositório no GitHub se um novo
   "run" do workflow começou sozinho, logo depois de publicar (isso confirma
   que o aviso automático funcionou).
4. Depois que esse run terminar (~1 a 3 minutos), acesse
   `zamtech.com.br/blog` e veja se o artigo apareceu.

## 10. Sobre o `.env` e o PASSO-A-PASSO-CPANEL-BLOG.md antigo

O arquivo `PASSO-A-PASSO-CPANEL-BLOG.md` (o banco de dados MySQL do blog
antigo) não é mais usado — pode deixar ele aí só como histórico, ou apagar,
como preferir. O banco `blog` que você criou lá também não precisa mais
dele, mas não tem pressa nem risco em deixar existindo.
