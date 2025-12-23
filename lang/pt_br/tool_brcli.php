<?php
/**
 * admin tool brcli
 * Backup & restore command line interface
 * @package admin
 * @subpackage tool
 * @author Paulo Júnior <pauloa.junior@ufla.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['pluginname'] = 'Interface de linha de comando para backup e restauração';
$string['unknowoption'] = 'Opção inválida: {$a}';
$string['noadminaccount'] = 'Erro: Não há uma conta de administrador cadastrada!';
$string['directoryerror'] = 'Erro: O diretório de destino informado não existe ou não pode ser escrito!';
$string['nocategory'] = 'Erro: A categoria informada não existe!';
$string['performingbck'] = 'Iniciando backup do curso {$a}...';
$string['performingres'] = 'Restaurando backup do curso {$a}...';
$string['operationdone'] = 'Finalizado!';
$string['invalidbackupfile'] = 'Arquivo de backup inválido: {$a}';
$string['helpoptionbck'] =
'Realiza o backup de cursos do Moodle via linha de comando.

É possível:
- realizar backup de TODOS os cursos da plataforma, ou
- realizar backup apenas dos cursos de uma categoria específica.

Opções:
--all                       Realiza o backup de TODOS os cursos (exceto a página inicial).
--categoryid=INTEGER        ID da categoria para backup (ignorado quando --all é utilizado).
--destination=STRING        Caminho onde os arquivos de backup serão armazenados.
--users=0|1                 Incluir usuários no backup (padrão: 0).
--anonymize=0|1             Anonimiza os dados dos usuários (aplicável apenas quando --users=1).
-h, --help                  Exibe esta ajuda.

Observações:
- Por padrão, os backups são gerados SEM usuários.
- A opção --anonymize só tem efeito quando --users=1.
- Ao utilizar --all, não é necessário informar --categoryid.

Exemplos:

Backup de todos os cursos sem usuários:
    php admin/tool/brcli/backup.php --all --destination=/moodle/backup/

Backup de todos os cursos com usuários anonimizados:
    php admin/tool/brcli/backup.php --all --destination=/moodle/backup/ --users=1 --anonymize=1

Backup dos cursos de uma categoria específica:
    php admin/tool/brcli/backup.php --categoryid=3 --destination=/moodle/backup/

Backup de cursos de uma categoria com usuários:
    php admin/tool/brcli/backup.php --categoryid=3 --destination=/moodle/backup/ --users=1
';

$string['helpoptionres'] = 
'Restaura todos os arquivos de backup contidos em um diretório.

Options:
--categoryid=INTEGER        ID da categoria onde os backup serão restaurados.
--source=STRING             Caminho onde os arquivos de backup (.mbz) estão armazenados.
-h, --help                  Exibe a ajuda.

Exemplo:
    sudo -u www-data /usr/bin/php admin/tool/brcli/restore.php --categoryid=1 --source=/moodle/backup/
';