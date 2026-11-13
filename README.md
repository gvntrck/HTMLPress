# HTMLPress

Publique páginas HTML ou PHP no WordPress, com suporte a versões em português, inglês e espanhol. Envie um arquivo individual ou um pacote ZIP com imagens, CSS e JavaScript diretamente pelo painel. Cada versão aparece como uma página real em **Páginas**.

![Tela de criação de uma página HTML no WordPress, com campos de título, slug e arquivos por idioma](Captura.png)

## Recursos

- Publicação de HTML completo sem alterar o editor padrão do WordPress.
- Versão em português obrigatória e versões opcionais em inglês e espanhol.
- Envio de HTML/PHP single-file ou ZIP com os arquivos da página, preservando o suporte HTML existente.
- PHP executado no contexto do WordPress, com acesso às funções dos plugins ativos, incluindo WooCommerce.
- Slug compartilhado entre os idiomas e URLs específicas para cada versão.
- Metadados de URL canônica e idiomas alternativos para SEO.
- Possibilidade de usar uma página HTMLPress como página inicial.
- Edição, lixeira e restauração pelo painel do WordPress.

> O plugin não traduz o conteúdo automaticamente. Os arquivos de cada idioma e os links visíveis entre eles devem ser preparados por você.

## Requisitos

- WordPress 6.5 ou superior.
- PHP 8.1 ou superior, com a extensão `finfo`.
- Extensão PHP `zip` (`ZipArchive`) para enviar arquivos ZIP.
- Links permanentes amigáveis ativados.

Para gerenciar páginas, o usuário precisa das permissões `manage_options`, `unfiltered_html`, `edit_pages`, `publish_pages` e `upload_files`. Em multisite, a permissão `unfiltered_html` é restrita a superadministradores; a constante `DISALLOW_UNFILTERED_HTML` bloqueia o gerenciamento.

O envio de PHP exige também `edit_themes` e, em multisite, ser superadministrador. `DISALLOW_FILE_EDIT` ou `DISALLOW_FILE_MODS` ativos bloqueiam novos envios de PHP (inclusive em ZIP); HTML continua disponível. Esses bloqueios não desativam o PHP já publicado. As verificações usam capacidades do WordPress: não conceda essas permissões a usuários não confiáveis.

## Instalação

1. No WordPress, acesse **Plugins → Adicionar plugin → Enviar plugin**.
2. Envie o ZIP de instalação do HTMLPress e ative o plugin.
3. Em **Configurações → Links permanentes**, ative uma estrutura de links amigável.

## Como usar

1. Acesse **Páginas → Adicionar página HTML/PHP**.
2. Informe o título e o slug público compartilhados entre os idiomas.
3. Envie o arquivo HTML, PHP ou ZIP em português. Se desejar, envie também as versões em inglês e espanhol.
4. Clique em **Salvar página HTMLPress**. As versões enviadas são publicadas imediatamente.

Cada arquivo HTML deve estar em UTF-8 e conter um documento completo, com as tags `<html>`, `<head>` e `<body>` e seus respectivos fechamentos.

### Páginas PHP geradas por IA

Peça à IA: **“Crie uma página PHP para o HTMLPress, executada dentro do WordPress já carregado. Não inclua wp-load.php. Gere um documento HTML completo em UTF-8; use wp_head() e wp_footer() quando precisar dos scripts e estilos do WordPress/WooCommerce. Para assets do pacote, use $args['assets_url']; para o action de formulários, use $args['page_url']. Não use arquivos PHP auxiliares nem exit/die.”**

É possível usar `get_header()`/`get_footer()` em vez de gerar o documento completo, ou consultar produtos com `wc_get_products()` quando o WooCommerce estiver ativo. O plugin não inclui cabeçalho, rodapé ou hooks automaticamente: a página deve fazê-lo. Não chame `wp_head()`/`wp_footer()` duas vezes quando o tema já os chama.

```php
<?php
// WordPress e plugins ativos já estão carregados. Não importe wp-load.php.
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <link rel="stylesheet" href="<?php echo esc_url($args['assets_url'] . 'css/estilo.css'); ?>">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main>
    <?php if (function_exists('wc_get_products')) : ?>
        <?php foreach (wc_get_products(['status' => 'publish', 'limit' => 12]) as $product) : ?>
            <a href="<?php echo esc_url($product->get_permalink()); ?>">
                <?php echo esc_html($product->get_name()); ?>
            </a>
        <?php endforeach; ?>
    <?php endif; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
```

A sintaxe PHP é verificada no envio, **sem executar o código**. Isso não valida a segurança nem garante que dependências e lógica funcionem. O template é executado em arquivo temporário fora das pastas públicas e removido ao terminar; `__DIR__`/`__FILE__` não apontam ao pacote enviado. Não use esses caminhos para localizar assets ou incluir outros PHP. Erros de execução capturáveis retornam uma mensagem genérica com HTTP 500; páginas PHP enviam cabeçalhos de não cache (configure também caches externos para ignorá-las).

### URLs e idiomas

| Idioma | Página comum | Página inicial |
| --- | --- | --- |
| Português (obrigatório) | `/slug/` | `/` |
| Inglês (opcional) | `/en/slug/` | `/en/` |
| Espanhol (opcional) | `/es/slug/` | `/es/` |

Para configurar a página inicial, acesse **Configurações → Leitura**, escolha uma página inicial estática e selecione a versão em português.

### Editar uma página

Em **Páginas**, edite qualquer versão do grupo. Deixe o campo de arquivo vazio para manter o conteúdo daquele idioma; um novo envio substitui seu HTML e seus arquivos associados. É possível adicionar traduções posteriormente.

O slug pode ser alterado e afeta as URLs de todos os idiomas, exceto as da página inicial. **Links antigos não são redirecionados automaticamente** após a alteração: atualize os links no seu HTML.

## Preparar um ZIP

Inclua **uma única entrada**, `index.html` ou `index.php`, e os assets estáticos necessários, diretamente na raiz do ZIP ou dentro de uma única pasta principal:

```text
index.html
css/
  estilo.css
js/
  site.js
imagens/
  foto.jpg
```

Para PHP, substitua `index.html` por `index.php`. Não inclua as duas entradas nem arquivos PHP auxiliares.

Os caminhos relativos dos assets são preservados usando uma tag `<base>`; URLs absolutas e caminhos iniciados por `/` permanecem inalterados. Em PHP, prefira `$args['assets_url']` para assets e `$args['page_url']` para formulários e links da própria página, pois URLs relativas (inclusive `#ancora` e `?parametro`) usam a base dos assets. Não adicione outra tag `<base>`. SVG, links simbólicos, caminhos inseguros e extensões desconhecidas são recusados.

**Limites por idioma:** HTML/PHP de até 2 MB; ZIP de até 10 MB, com até 100 arquivos de até 2 MB cada e 10 MB no total descompactado. Os limites de upload e POST do PHP também se aplicam.

## Segurança e armazenamento

**Publique somente conteúdo confiável.** O HTML é servido na mesma origem do site, e seus scripts executam no navegador dos visitantes.

**PHP não é isolado em sandbox:** tem os mesmos privilégios do processo WordPress, incluindo acesso ao banco de dados e aos arquivos do servidor. Restrinja a administração a pessoas de confiança e revise código gerado por IA. Validação de sintaxe não detecta código malicioso; ações que alteram dados precisam de nonces, validação e verificações de permissão no próprio template. Não coloque segredos em arquivos estáticos do pacote.

O código principal HTML/PHP é armazenado em metadados privados, fora do conteúdo público da página. Apenas assets estáticos dos pacotes ficam em `uploads/htmlpress`, fora da pasta do plugin; `index.php` nunca é gravado ali nem servido pela rota de assets. Ao desativar o plugin, conteúdo e metadados permanecem, mas as rotas e a renderização personalizadas deixam de funcionar enquanto ele estiver inativo.

Cada idioma pode ser enviado à lixeira, restaurado ou excluído individualmente. Para editar uma tradução que está na lixeira, restaure-a primeiro.

## Testes locais

Execute `bash tests/run.sh` em um ambiente com PHP CLI e ZipArchive. A suíte verifica sintaxe e comportamentos isolados, incluindo permissões, ZIPs e renderização simulada. Ela não substitui validação em uma instalação real de WordPress com WooCommerce; teste em homologação antes de publicar.
