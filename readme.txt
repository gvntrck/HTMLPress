HTMLPress 1.4.1
==============

Requisitos: WordPress 6.5+, PHP 8.1+ e finfo. Para ZIP, habilite ZipArchive. Ative links permanentes amigáveis.

Instalação: envie o ZIP do plugin em Plugins > Adicionar plugin > Enviar plugin e ative.

Uso: Páginas > Adicionar página HTML/PHP. Informe título e slug. Envie HTML (.html/.htm), PHP (.php) individual ou ZIP por idioma. PT-BR é obrigatório; EN e ES são opcionais. Cada versão enviada é publicada imediatamente como página real, listada em Páginas. Não há tradução automática.

HTML deve ser UTF-8 e conter documento completo com html, head e body. PHP deve ser UTF-8, ter sintaxe válida e gerar HTML. WordPress e plugins ativos (incluindo WooCommerce) já estão carregados: não inclua wp-load.php. Use wp_head()/wp_footer() quando necessários, ou get_header()/get_footer() do tema. Não há inclusão automática desses hooks.

ZIP: exatamente uma entrada index.html OU index.php, com CSS, JS, imagens e outros assets estáticos permitidos. Pode haver uma única pasta principal envolvendo o projeto. PHP auxiliares, SVG, links simbólicos, caminhos inseguros e extensões desconhecidas são recusados. index.php nunca é publicado como asset.

Assets relativos usam uma tag base. Para PHP, prefira $args['assets_url'] para assets e $args['page_url'] para action de formulários e links da própria página. URLs absolutas ou iniciadas por / não são alteradas. Não use __DIR__/__FILE__ para assets ou includes: o PHP é executado em arquivo temporário fora das pastas públicas, removido ao terminar. Não use exit/die nem outra tag base.

Limites por idioma: 2 MB por arquivo; ZIP de 10 MB, até 100 arquivos e 10 MB descompactados. Aplicam-se também os limites de upload/POST do servidor.

Editar: abra qualquer versão em Páginas > Editar. Campo vazio mantém o conteúdo atual. Novo arquivo/ZIP substitui conteúdo e assets apenas daquele idioma. É possível alternar HTML/PHP sem mudar o ID. Título e slug são compartilhados. Alterar slug altera URLs, exceto da página inicial; links antigos não são redirecionados automaticamente.

URLs: /slug/, /en/slug/ e /es/slug/. Para página inicial, selecione a versão portuguesa em Configurações > Leitura: /, /en/ e /es/.

Administração restrita por capacidades: manage_options, unfiltered_html, edit_pages, publish_pages e upload_files. Para enviar PHP, também edit_themes e, em multisite, superadministrador. DISALLOW_UNFILTERED_HTML bloqueia o gerenciamento; DISALLOW_FILE_EDIT/DISALLOW_FILE_MODS bloqueiam novos envios PHP, inclusive em ZIP, mas não desativam PHP já publicado.

SEGURANÇA: PHP não é sandbox. Executa com acesso aos arquivos e banco de dados do WordPress. Restrinja as permissões a administradores de confiança e revise código gerado por IA. Sintaxe é verificada sem execução no envio; isso não verifica segurança nem dependências. O template precisa validar entradas, escapar saídas e proteger ações com nonces/permissões. Scripts HTML executam na origem do site. Não inclua segredos nos assets estáticos.

Código HTML/PHP fica em metadados privados; apenas assets estáticos ficam em uploads/htmlpress e são servidos por rota controlada com MIME fixo e nosniff. PHP envia cabeçalhos de não cache; exclua essas páginas também de caches externos. Falhas capturáveis retornam HTTP 500 sem detalhes ao visitante.

Cada idioma pode ir à lixeira, ser restaurado ou excluído separadamente. Restaure traduções na lixeira antes de substituí-las. Desativar o plugin preserva os dados, mas desativa rotas e renderização. Veja README.md para exemplo PHP e instruções completas.
