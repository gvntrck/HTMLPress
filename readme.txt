HTMLPress
=========

Requisitos: WordPress 6.5 ou superior, PHP 8.1 ou superior e extensão finfo. Ative links permanentes amigáveis. Instale o ZIP em Plugins > Adicionar plugin > Enviar plugin e ative-o.

Crie em Páginas > Adicionar página HTML. O acesso fica no menu, sem depender de avisos administrativos. Informe título, slug e o HTML PT-BR obrigatório. EN e ES são opcionais: somente os idiomas enviados geram páginas. Cada arquivo deve ser UTF-8, conter um documento HTML completo com <html>, <head> e <body> e seus fechamentos, e ter no máximo 2 MB. Ao clicar em “Salvar página HTML”, as versões enviadas são publicadas imediatamente. Você pode adicionar traduções depois ao editar qualquer versão, sem alterar os IDs e URLs existentes. O seletor só aparece quando existe mais de um idioma.

Para editar, acesse Páginas > [página] > Editar em qualquer versão; o formulário mostra os três campos de upload. As substituições de conteúdo são opcionais e preservam IDs e URLs. O título é compartilhado e editável; o slug não pode ser alterado. Criar uma página HTMLPress em URL ocupada é recusado. Se uma página normal for criada ou movida para uma URL reservada pelo plugin, a rota HTMLPress é preservada por sufixo ou reversão da alteração.

Para páginas que não são a página inicial, as URLs do trio são /slug/, /en/slug/ e /es/slug/.

Para usar o trio como página inicial, configure a página inicial estática em Configurações > Leitura e selecione a versão portuguesa. As URLs são /, /en/ e /es/. Aliases antigos redirecionam com HTTP 301.

O editor normal do WordPress permanece inalterado. O upload aceita apenas HTML; não há envio de pacote ZIP de CSS, JS ou imagens. Use recursos inline ou URLs absolutas. O HTML publicado é same-origin e seus scripts executam no navegador: publique somente conteúdo confiável.

O gerenciamento exige manage_options, unfiltered_html, edit_pages, publish_pages e upload_files. Em multisite, somente superadministradores passam pela verificação unfiltered_html do WordPress; DISALLOW_UNFILTERED_HTML ativo bloqueia o gerenciamento. Os documentos são guardados em metadados privados, não em uploads. Não há tradução automática.

Para preservar o trio, o plugin bloqueia atualmente lixeira e exclusão individual de páginas gerenciadas. Ao desativar o plugin, conteúdo e metadados permanecem, mas as rotas e a renderização personalizadas dependem do plugin ativo.
Validação: lint e testes PHP isolados. Sem validação em instalação WordPress.
Validação: lint e testes PHP isolados. Sem validação em instalação WordPress.
