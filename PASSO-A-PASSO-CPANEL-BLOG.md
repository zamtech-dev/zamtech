# Passo a passo: criar o banco de dados do Blog no cPanel

Isso aqui é só pra você fazer uma vez. Depois disso o blog fica funcionando sozinho.

## 1. Criar o banco de dados

1. Entre no cPanel da Hostgator.
2. Procure por **"Bancos de Dados MySQL"** (MySQL Databases).
3. Em "Criar novo banco de dados", digite: `blog`
   - O cPanel vai colocar um prefixo automático (o mesmo que aparece nos outros bancos, tipo `ztclas09_`). O nome final vai ficar algo como `ztclas09_blog`.
4. Clique em "Criar banco de dados".

## 2. Criar o usuário do banco

1. Ainda na mesma página, desça até "Usuários MySQL" (MySQL Users).
2. Em "Criar novo usuário", digite: `blog` (vai virar `ztclas09_blog` também).
3. Clique em "Gerar senha" (Password Generator) pra criar uma senha forte, ou crie a sua. **Copie essa senha, você vai precisar dela no passo 4.**
4. Clique em "Criar usuário".

## 3. Ligar o usuário ao banco

1. Desça até "Adicionar usuário ao banco de dados" (Add User to Database).
2. Escolha o usuário `ztclas09_blog` e o banco `ztclas09_blog`.
3. Clique em "Adicionar".
4. Na tela de privilégios, marque **"ALL PRIVILEGES"** (todos os privilégios).
5. Clique em "Fazer alterações" (Make Changes).

## 4. Rodar o SQL que cria as tabelas

1. No cPanel, procure por **"phpMyAdmin"**.
2. Clique no banco `ztclas09_blog` na barra lateral esquerda.
3. Clique na aba **"SQL"** (no topo).
4. Abra o arquivo `sql/blog_schema.sql` que te mandei, copie todo o conteúdo, cole nessa caixa de SQL do phpMyAdmin.
5. Clique em "Executar" (Go).
6. Deve aparecer uma mensagem de sucesso e 3 tabelas novas na barra lateral: `blog_admin_usuarios`, `blog_artigos`, `blog_login_tentativas`.

Esse SQL já cria seu usuário de admin do blog automaticamente, com login `zamtech.marketing@proton.me` e a senha que já te passei.

## 5. Me devolver 3 informações

Depois de fazer os passos acima, me manda só isso aqui:

- **Nome do banco** (algo como `ztclas09_blog`)
- **Nome do usuário do banco** (o mesmo, `ztclas09_blog`)
- **Senha que você criou pro usuário** no passo 2

Com isso eu termino de configurar o arquivo `_config.php` do blog com os dados reais e você já pode publicar o primeiro artigo.

---

### Sua senha de acesso ao painel do blog (`/blog/admin`)

- **Login:** zamtech.marketing@proton.me
- **Senha:** `tnbT4xwvn4FXMw9z`

Guarda essa senha em um lugar seguro (gerenciador de senhas, por exemplo). Assim que entrar, tem uma página de **Configurações** pra você trocar essa senha por uma sua, se quiser.
