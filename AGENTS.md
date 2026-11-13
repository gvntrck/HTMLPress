# Regras do projeto

- Sempre que qualquer arquivo do plugin for alterado, fazer bump obrigatório da versão no campo `Version` do cabeçalho de `htmlpress.php`, na mesma entrega. Manter sincronizadas outras declarações da versão atual, quando existirem.
- Sempre que fizer bump da versão, executar `python scripts/build-zip.py` para gerar `dist/htmlpress-<versão>.zip`. Manter somente o ZIP mais recente em `dist`, apagando os ZIPs anteriores apenas após validar e salvar o novo pacote. Não apagar ZIPs fora de `dist`.
- Verificar o empacotamento com `python -m unittest discover -s tests -p "test_build_zip.py"`. O script requer Python 3 e 7-Zip.
