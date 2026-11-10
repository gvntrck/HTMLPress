HTMLPress
=========

Requisitos: WordPress 6.5 ou superior, PHP 8.1 ou superior e extensão finfo. Para enviar ZIPs de conteúdo, habilite também a extensão PHP zip (ZipArchive). Ative links permanentes amigáveis. Instale o ZIP em Plugins > Adicionar plugin > Enviar plugin e ative-o. Versão 1.3.1.

Crie em Páginas > Adicionar página HTML. Informe título, slug e o conteúdo PT-BR obrigatório. Há apenas um campo por idioma: selecione um HTML individual (.html ou .htm) ou um ZIP com index.html e suas pastas de imagens, CSS e JS. O ZIP pode conter os arquivos diretamente ou dentro de uma única pasta principal. EN e ES são opcionais: somente os idiomas enviados geram páginas. Cada HTML deve ser UTF-8 e conter um documento completo com <html>, <head> e <body> e seus fechamentos. Ao clicar em “Salvar página HTML”, as versões enviadas são publicadas imediatamente. Você pode adicionar traduções depois, sem alterar IDs e URLs existentes. Links visíveis entre idiomas devem ser incluídos no próprio HTML; o plugin mantém os metadados SEO de idiomas publicados.

Para editar, acesse Páginas > [página] > Editar em qualquer versão. Deixe o campo de arquivo vazio para manter o conteúdo daquele idioma. Um novo HTML ou ZIP substitui o conteúdo e os assets anteriores daquele idioma; IDs são preservados. O slug público pode ser editado e é compartilhado entre os idiomas: alterá-lo atualiza as URLs, exceto as da página inicial. Slugs duplicados ou reservados são recusados. Links antigos não são redirecionados automaticamente; atualize os links no HTML. Caminhos relativos das pastas dentro do ZIP são preservados. Limites: 2 MB por HTML individual; ZIP de até 10 MB, com até 100 arquivos de 2 MB cada e 10 MB descompactados por idioma. Os limites PHP de upload e POST também se aplicam.

Para páginas que não são a página inicial, as URLs do trio são /slug/, /en/slug/ e /es/slug/.

Para usar o trio como página inicial, configure a página inicial estática em Configurações > Leitura e selecione a versão portuguesa. As URLs são /, /en/ e /es/. Aliases antigos redirecionam com HTTP 301.

O editor normal do WordPress permanece inalterado. O ZIP aceita tipos comuns de conteúdo estático; arquivos PHP, SVG, links simbólicos, caminhos inseguros e extensões desconhecidas são recusados. Recursos relativos apontam para os arquivos enviados; URLs absolutas e iniciadas por / permanecem inalteradas e devem apontar a recursos externos/WordPress apropriados. Assets são armazenados no diretório uploads/htmlpress, fora do plugin, e servidos por rota controlada, com MIME fixo e nosniff. O HTML publicado é same-origin e scripts executam no navegador: publique somente conteúdo confiável.

O gerenciamento exige manage_options, unfiltered_html, edit_pages, publish_pages e upload_files. Em multisite, somente superadministradores passam pela verificação unfiltered_html do WordPress; DISALLOW_UNFILTERED_HTML ativo bloqueia o gerenciamento. O HTML principal é guardado em metadados privados; os arquivos do pacote ficam em uploads/htmlpress. Não há tradução automática.

Cada página/idioma pode ser enviado individualmente à lixeira, restaurado ou excluído definitivamente pelo WordPress. Isso não remove páginas ou assets dos demais idiomas. Para editar uma tradução que esteja na lixeira, restaure-a primeiro. Ao desativar o plugin, conteúdo e metadados permanecem, mas as rotas e a renderização personalizadas dependem do plugin ativo.
Validação: lint e testes PHP isolados. Sem validação em instalação WordPress.
